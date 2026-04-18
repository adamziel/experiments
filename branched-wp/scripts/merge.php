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
    fwrite(STDERR, "Usage: php merge.php {source-branch} {target-branch} [db-path] [--strategy=abort|ours|theirs] [--on-id-collision=conflict|renumber]\n");
    exit(1);
}

$source  = $argv[1];
$target  = $argv[2];
$db_path = $argv[3] ?? __DIR__ . '/../branchfs.db';
$strategy = 'abort';
$on_id_collision = 'conflict';

for ($i = 3; $i < $argc; $i++) {
    $a = $argv[$i];
    if (strpos($a, '--strategy=') === 0) {
        $strategy = substr($a, strlen('--strategy='));
    } elseif ($a === '--strategy' && isset($argv[$i + 1])) {
        $strategy = $argv[++$i];
    } elseif (strpos($a, '--on-id-collision=') === 0) {
        $on_id_collision = substr($a, strlen('--on-id-collision='));
    } elseif ($a === '--on-id-collision' && isset($argv[$i + 1])) {
        $on_id_collision = $argv[++$i];
    }
}

if (!in_array($strategy, ['abort', 'ours', 'theirs'], true)) {
    fwrite(STDERR, "merge: invalid --strategy '$strategy' (must be abort|ours|theirs)\n");
    exit(1);
}
if (!in_array($on_id_collision, ['conflict', 'renumber'], true)) {
    fwrite(STDERR, "merge: invalid --on-id-collision '$on_id_collision' (must be conflict|renumber)\n");
    exit(1);
}

if (!extension_loaded('branchfs')) {
    fwrite(STDERR, "ERROR: branchfs extension not loaded\n");
    exit(1);
}

require_once __DIR__ . '/opcache.php';
require_once __DIR__ . '/sqlite_retry.php';

branchfs_set_db($db_path);

echo "=== BranchFS Merge ===\n";
echo "Source:    $source\n";
echo "Target:    $target\n";
echo "Strategy:  $strategy\n";
echo "On-ID-collision: $on_id_collision\n\n";

$db = new SQLite3($db_path);
// 15s busy timeout + sqlite_retry_busy() wrappers around the two write
// transactions below handle SQLITE_BUSY under concurrent writer load
// (parallel HTTP + SFTP + MySQL + other branchctl invocations).
$db->busyTimeout(15000);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA wal_autocheckpoint = 500');

function merge_branch_id(SQLite3 $db, string $name): int {
    return (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($name) . "'");
}

/**
 * Hard-coded WordPress foreign-key rewrite map used by --on-id-collision=renumber.
 *
 * Keys and values are the BARE suffix after the `b{id}_wp_` branch prefix.
 * For example, `b2_wp_postmeta` has suffix `postmeta`.
 *
 * Values are [fk_table_suffix, fk_column] pairs that reference the PK-owning
 * table's PK column.
 */
function merge_fk_map(): array {
    return [
        'posts' => [
            ['postmeta',           'post_id'],
            ['comments',           'comment_post_ID'],
            ['term_relationships', 'object_id'],
            ['posts',              'post_parent'],
        ],
        'users' => [
            ['usermeta', 'user_id'],
            ['posts',    'post_author'],
            ['comments', 'user_id'],
        ],
        'comments' => [
            ['commentmeta', 'comment_id'],
        ],
        'terms' => [
            ['term_taxonomy', 'term_id'],
        ],
        'term_taxonomy' => [
            ['term_relationships', 'term_taxonomy_id'],
        ],
    ];
}

/**
 * True iff $table has a single-column INTEGER PRIMARY KEY AUTOINCREMENT.
 *
 * Detection: prefer DDL inspection (sqlite_master.sql LIKE '%AUTOINCREMENT%'),
 * fall back to PRAGMA table_info for a single-INTEGER-PK column.
 */
function merge_table_is_autoinc_pk(SQLite3 $db, string $table): bool {
    $ddl = $db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($table) . "'"
    );
    if (is_string($ddl) && stripos($ddl, 'AUTOINCREMENT') !== false) {
        return true;
    }
    // Fallback: single PK column typed INTEGER.
    $pk_cols = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($table) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if ((int)$row['pk'] > 0) {
            $pk_cols[] = $row;
        }
    }
    if (count($pk_cols) !== 1) return false;
    return strtoupper((string)$pk_cols[0]['type']) === 'INTEGER';
}

/**
 * Current MAX(pk_col) from a (prefixed) table, or 0 if empty/missing.
 */
function merge_table_max_pk(SQLite3 $db, string $table, string $pk_col): int {
    $v = $db->querySingle(
        'SELECT MAX("' . SQLite3::escapeString($pk_col) . '") FROM "'
        . SQLite3::escapeString($table) . '"'
    );
    return $v === null ? 0 : (int)$v;
}

/**
 * Rewrite all FK-column occurrences of $old_id → $new_id in $row_json, but
 * only for the columns declared in the supplied FK map entry list.
 *
 * $fk_cols_for_this_table is a list of column names (strings) in the CURRENT
 * row's table that point at a renumbered PK in some other table. The caller
 * supplies only relevant columns for the row's table.
 *
 * Leaves NULL and 0 (unset FK) columns unchanged.
 */
function merge_rewrite_row_fks(string $row_json, array $fk_rewrites): string {
    $row = json_decode($row_json, true);
    if (!is_array($row)) return $row_json;
    $changed = false;
    foreach ($fk_rewrites as $entry) {
        [$col, $map] = $entry; // $map : old_id => new_id
        if (!array_key_exists($col, $row)) continue;
        $v = $row[$col];
        if ($v === null) continue;
        if ((int)$v === 0) continue;
        $iv = (int)$v;
        if (isset($map[$iv])) {
            $row[$col] = $map[$iv];
            $changed = true;
        }
    }
    return $changed ? json_encode($row, JSON_UNESCAPED_UNICODE) : $row_json;
}

/**
 * Replace the PK column value in a row_json blob with $new_id.
 */
function merge_row_with_pk(string $row_json, string $pk_col, int $new_id): string {
    $row = json_decode($row_json, true);
    if (!is_array($row)) return $row_json;
    $row[$pk_col] = $new_id;
    return json_encode($row, JSON_UNESCAPED_UNICODE);
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

// ── Schema-merge helpers ─────────────────────────────────────────────────────
//
// Column-level 3-way schema diff for tables that exist on BOTH source and
// target. Inputs (per side):
//   - DDL string from sqlite_master (CREATE TABLE …)
//   - List of CREATE INDEX … statements from sqlite_master
// We normalize each to a comparable shape so:
//   - column adds/drops/type changes show up as per-column diffs
//   - index adds/drops show up as per-index diffs
// keyed in a way that survives the prefix-rename game (index names / table
// names embed the b{branch_id}_wp_ prefix and must be normalized away).

/** Read live columns of a table from PRAGMA table_info: ordered list of
 *  ['name' => …, 'type' => …, 'notnull' => 0|1, 'dflt_value' => …, 'pk' => 0|1+]. */
function schema_columns_from_pragma(SQLite3 $db, string $table): array {
    $cols = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($table) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = [
            'name'       => (string)$row['name'],
            'type'       => (string)$row['type'],
            'notnull'    => (int)$row['notnull'],
            'dflt_value' => $row['dflt_value'],
            'pk'         => (int)$row['pk'],
        ];
    }
    return $cols;
}

/** Read live indexes of a table from sqlite_master: list of
 *  ['name'=>…,'sql'=>…]. Auto-indexes (sql IS NULL) are skipped. */
function schema_indexes_from_master(SQLite3 $db, string $table): array {
    $idx = [];
    $st = $db->prepare(
        "SELECT name, sql FROM sqlite_master WHERE type='index' "
      . "AND tbl_name=:t AND sql IS NOT NULL ORDER BY name"
    );
    $st->bindValue(':t', $table, SQLITE3_TEXT);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $idx[] = ['name' => (string)$row['name'], 'sql' => (string)$row['sql']];
    }
    return $idx;
}

/** Parse a CREATE TABLE DDL string into a normalized columns list of the
 *  same shape that schema_columns_from_pragma() returns.
 *
 *  We attach the parsed schema to a temp DB, then use PRAGMA table_info on it.
 *  This sidesteps the surprisingly hairy job of writing a full SQLite DDL
 *  parser in PHP. The temp DB lives only as long as this function call.
 */
function schema_columns_from_ddl(string $ddl, string $original_name): array {
    if ($ddl === '') return [];
    $tmp = new SQLite3(':memory:');
    try {
        // Force the table name to a known stable string so we can PRAGMA it.
        $rewritten = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($original_name, '/') . '"?(\s*\()/is',
            '$1IF NOT EXISTS "schema_probe"$2',
            $ddl, 1
        );
        if (!$rewritten) return [];
        @$tmp->exec($rewritten);
        $cols = [];
        $r = $tmp->query('PRAGMA table_info("schema_probe")');
        if (!$r) return [];
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = [
                'name'       => (string)$row['name'],
                'type'       => (string)$row['type'],
                'notnull'    => (int)$row['notnull'],
                'dflt_value' => $row['dflt_value'],
                'pk'         => (int)$row['pk'],
            ];
        }
        return $cols;
    } finally {
        $tmp->close();
    }
}

/** Strip a prefix-specific table or index name out of an index DDL string so
 *  two indexes that differ ONLY in their b{N}_wp_ prefix compare equal.
 *  Also trims whitespace, drops double-quotes around identifiers, normalizes
 *  spacing around parens, and uppercases keywords for stable comparison. */
function schema_normalize_index_ddl(string $ddl, string $prefix): string {
    $norm = str_replace($prefix, 'BPFX_', $ddl);
    // Strip double-quotes around identifiers (sqlite_master may or may not
    // emit them depending on how the DDL was originally written).
    $norm = str_replace('"', '', $norm);
    // Collapse whitespace and normalize whitespace around parens.
    $norm = preg_replace('/\s+/', ' ', trim($norm));
    $norm = preg_replace('/\s*\(\s*/', '(', $norm);
    $norm = preg_replace('/\s*\)\s*/', ')', $norm);
    $norm = preg_replace('/\s*,\s*/', ',', $norm);
    return $norm;
}

/** Normalized key for an index — the DDL minus any branch-specific prefix.
 *  Two indexes from different branches that describe the same shape will
 *  produce the same key. */
function schema_index_key(array $index, string $prefix): string {
    return schema_normalize_index_ddl($index['sql'], $prefix);
}

/** Compare two column dicts (from schema_columns_from_*) for "same shape".
 *  Ignores column ordering — only the per-column attributes matter. */
function schema_columns_equal(array $a, array $b): bool {
    // Normalize: trim / uppercase types, compare default values loosely.
    $norm = function (array $c): array {
        return [
            'name'       => $c['name'],
            'type'       => strtoupper(trim((string)$c['type'])),
            'notnull'    => (int)$c['notnull'],
            'dflt_value' => is_null($c['dflt_value']) ? null : (string)$c['dflt_value'],
            'pk'         => (int)$c['pk'],
        ];
    };
    return $norm($a) === $norm($b);
}

/**
 * Build column-level + index-level 3-way diff for a single table that
 * exists on both source and target.
 *
 * Returns an array with:
 *   ['ops' => [ ... apply ops ... ],
 *    'conflicts' => [ ... conflict descriptors ... ]]
 *
 * Each op is one of:
 *   ['type'=>'add_column',  'table'=>$tgt, 'col_def'=>$ddl_fragment]
 *   ['type'=>'drop_column', 'table'=>$tgt, 'col_name'=>$name]
 *   ['type'=>'modify_column', 'table'=>$tgt, 'col_name'=>$name, 'new_col_def'=>$ddl_fragment, 'src_col'=>$col_dict]
 *   ['type'=>'add_index', 'src_ddl'=>$sql, 'src_prefix'=>…, 'tgt_prefix'=>…]
 *   ['type'=>'drop_index', 'name'=>$tgt_index_name]
 *
 * Each conflict carries 'desc' + 'theirs_op' + 'ours_op' (ours_op is null
 * because keeping target's schema means doing nothing).
 */
function schema_diff_table(
    array $anc_cols, array $anc_idx,
    array $src_cols, array $src_idx,
    array $tgt_cols, array $tgt_idx,
    string $src_prefix, string $tgt_prefix,
    string $src_ddl, string $tgt_table
): array {
    $ops = [];
    $conflicts = [];

    // Build name => col dicts for each side.
    $by_name = function (array $cols): array {
        $o = [];
        foreach ($cols as $c) $o[$c['name']] = $c;
        return $o;
    };
    $a = $by_name($anc_cols);
    $s = $by_name($src_cols);
    $t = $by_name($tgt_cols);

    $all_names = array_unique(array_merge(
        array_keys($a), array_keys($s), array_keys($t)
    ));

    foreach ($all_names as $name) {
        $av = $a[$name] ?? null;
        $sv = $s[$name] ?? null;
        $tv = $t[$name] ?? null;

        if ($sv !== null && $tv !== null && schema_columns_equal($sv, $tv)) {
            // Both sides agree on this column.
            continue;
        }

        if ($av === null) {
            // Column is brand new on at least one side.
            if ($sv !== null && $tv === null) {
                // Source added it, target doesn't have it yet → ADD on target.
                $def = schema_extract_column_def($src_ddl, $name);
                if ($def === null) {
                    $conflicts[] = [
                        'desc' => "$tgt_table column '$name' (cannot extract column DDL from source)",
                        'theirs_op' => null, 'ours_op' => null,
                    ];
                    continue;
                }
                $ops[] = [
                    'type'    => 'add_column',
                    'table'   => $tgt_table,
                    'col_def' => $def,
                ];
            } elseif ($sv === null && $tv !== null) {
                // Target added it independently — keep it.
                continue;
            } elseif ($sv !== null && $tv !== null && !schema_columns_equal($sv, $tv)) {
                // Both added the same column with DIFFERENT shapes → CONFLICT.
                $def = schema_extract_column_def($src_ddl, $name);
                $conflicts[] = [
                    'desc' => "$tgt_table column '$name' (both branches added different definitions)",
                    'theirs_op' => $def !== null ? [
                        'type'        => 'modify_column',
                        'table'       => $tgt_table,
                        'col_name'    => $name,
                        'new_col_def' => $def,
                        'src_col'     => $sv,
                    ] : null,
                    'ours_op' => null,
                ];
            }
            continue;
        }

        // Ancestor had this column.
        if ($sv === null && $tv !== null) {
            if (schema_columns_equal($av, $tv)) {
                // Source dropped it, target unchanged → DROP on target.
                $ops[] = [
                    'type'     => 'drop_column',
                    'table'    => $tgt_table,
                    'col_name' => $name,
                ];
            } else {
                // Source dropped, target modified → CONFLICT.
                $conflicts[] = [
                    'desc' => "$tgt_table column '$name' (source dropped, target modified)",
                    'theirs_op' => [
                        'type'     => 'drop_column',
                        'table'    => $tgt_table,
                        'col_name' => $name,
                    ],
                    'ours_op' => null,
                ];
            }
            continue;
        }
        if ($sv === null && $tv === null) {
            // Both dropped — already gone, no-op.
            continue;
        }
        if ($sv !== null && $tv !== null) {
            // Both present, $sv != $tv (we returned earlier when equal).
            if (schema_columns_equal($sv, $av)) {
                // Source unchanged, target modified → keep target.
                continue;
            } elseif (schema_columns_equal($tv, $av)) {
                // Source modified, target unchanged → adopt source.
                $def = schema_extract_column_def($src_ddl, $name);
                if ($def === null) {
                    $conflicts[] = [
                        'desc' => "$tgt_table column '$name' (cannot extract source DDL for type change)",
                        'theirs_op' => null, 'ours_op' => null,
                    ];
                    continue;
                }
                $ops[] = [
                    'type'        => 'modify_column',
                    'table'       => $tgt_table,
                    'col_name'    => $name,
                    'new_col_def' => $def,
                    'src_col'     => $sv,
                ];
            } else {
                // Both modified differently → CONFLICT.
                $def = schema_extract_column_def($src_ddl, $name);
                $conflicts[] = [
                    'desc' => "$tgt_table column '$name' (both branches modified differently)",
                    'theirs_op' => $def !== null ? [
                        'type'        => 'modify_column',
                        'table'       => $tgt_table,
                        'col_name'    => $name,
                        'new_col_def' => $def,
                        'src_col'     => $sv,
                    ] : null,
                    'ours_op' => null,
                ];
            }
        }
    }

    // ── Index-level diff ─────────────────────────────────────────────────
    //
    // Match indexes by their normalized DDL (prefix-independent). For each
    // unique normalized DDL key, decide based on presence in src/tgt/anc.

    // Build map: normalized_key => [side_idx]
    $a_idx = [];
    $s_idx = [];
    $t_idx = [];
    foreach ($anc_idx as $i) $a_idx[schema_index_key($i, $src_prefix)] = $i;
    foreach ($src_idx as $i) $s_idx[schema_index_key($i, $src_prefix)] = $i;
    foreach ($tgt_idx as $i) $t_idx[schema_index_key($i, $tgt_prefix)] = $i;

    $all_keys = array_unique(array_merge(
        array_keys($a_idx), array_keys($s_idx), array_keys($t_idx)
    ));

    foreach ($all_keys as $key) {
        $av = $a_idx[$key] ?? null;
        $sv = $s_idx[$key] ?? null;
        $tv = $t_idx[$key] ?? null;

        $in_a = $av !== null;
        $in_s = $sv !== null;
        $in_t = $tv !== null;

        if ($in_s === $in_t) continue; // both have / both lack — no-op

        if (!$in_a) {
            if ($in_s && !$in_t) {
                // Source added an index target doesn't have → CREATE on target.
                $ops[] = [
                    'type'       => 'add_index',
                    'src_ddl'    => $sv['sql'],
                    'src_index_name' => $sv['name'],
                    'src_prefix' => $src_prefix,
                    'tgt_prefix' => $tgt_prefix,
                ];
            }
            // !$in_s && $in_t: target added it; keep.
        } else {
            if ($in_t && !$in_s) {
                // Source dropped it, target still has it → DROP from target.
                $ops[] = [
                    'type' => 'drop_index',
                    'name' => $tv['name'],
                ];
            }
            // $in_s && !$in_t: source has it, target dropped it → keep target.
        }
    }

    return ['ops' => $ops, 'conflicts' => $conflicts];
}

/** Best-effort extraction of one column's DDL fragment ("seo_title TEXT NOT
 *  NULL DEFAULT '50'") from a CREATE TABLE statement. We attach the schema
 *  to a temp DB and re-emit the column definition by reading PRAGMA + the
 *  raw DDL slice. This is conservative — falls back to type+nullable+default
 *  if we can't slice the original token range. */
function schema_extract_column_def(string $ddl, string $col_name): ?string {
    if ($ddl === '') return null;

    // Try to grab the column's exact slice from the DDL inside the parens.
    if (preg_match('/^[^(]*\((.*)\)\s*[^)]*$/s', $ddl, $m)) {
        $body = $m[1];
        // Split body on commas not inside parens.
        $depth = 0;
        $parts = [];
        $cur = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if ($cur !== '') $parts[] = trim($cur);

        foreach ($parts as $part) {
            // Match column name (possibly quoted).
            if (preg_match('/^"?(' . preg_quote($col_name, '/') . ')"?\s+(.+)$/is', $part, $cm)) {
                // Strip a leading PRIMARY/UNIQUE/CHECK/FOREIGN keyword to skip
                // table-level constraints accidentally matching.
                $rest_first = strtoupper(substr(trim($cm[2]), 0, 7));
                if (in_array(substr($rest_first, 0, 7), ['PRIMARY', 'FOREIGN'])) continue;
                if (substr($rest_first, 0, 6) === 'UNIQUE') continue;
                if (substr($rest_first, 0, 5) === 'CHECK')  continue;
                return '"' . $col_name . '" ' . trim($cm[2]);
            }
        }
    }

    // Fallback: derive from a temp-DB PRAGMA.
    $tmp = new SQLite3(':memory:');
    try {
        $rewritten = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?[^"\s(]+"?(\s*\()/is',
            '$1IF NOT EXISTS "schema_probe"$2',
            $ddl, 1
        );
        if (!$rewritten) return null;
        @$tmp->exec($rewritten);
        $r = $tmp->query('PRAGMA table_info("schema_probe")');
        if (!$r) return null;
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            if ((string)$row['name'] !== $col_name) continue;
            $def = '"' . $col_name . '"';
            if ($row['type']) $def .= ' ' . $row['type'];
            if ((int)$row['notnull']) $def .= ' NOT NULL';
            if ($row['dflt_value'] !== null) {
                $dv = (string)$row['dflt_value'];
                $def .= ' DEFAULT ' . $dv;
            }
            return $def;
        }
        return null;
    } finally {
        $tmp->close();
    }
}

/** Apply a single schema op against the target table. May rebuild the table
 *  for DROP/MODIFY ops on older SQLite, or use ALTER TABLE on 3.35+. */
function schema_apply_op(SQLite3 $db, array $op, string $src_prefix, string $tgt_prefix): void {
    switch ($op['type']) {
        case 'add_column':
            // ALTER TABLE works for ADD COLUMN even on old SQLite.
            $db->exec('ALTER TABLE "' . SQLite3::escapeString($op['table']) . '" '
                    . 'ADD COLUMN ' . $op['col_def']);
            break;

        case 'drop_column':
            // ALTER TABLE … DROP COLUMN requires SQLite 3.35+; try and fall
            // back to table-rebuild on failure.
            $tgt = $op['table'];
            try {
                @$db->exec('ALTER TABLE "' . SQLite3::escapeString($tgt) . '" '
                         . 'DROP COLUMN "' . SQLite3::escapeString($op['col_name']) . '"');
                // Verify it actually dropped.
                $still_there = false;
                $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($tgt) . '")');
                while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                    if ((string)$row['name'] === $op['col_name']) { $still_there = true; break; }
                }
                if ($still_there) {
                    schema_rebuild_table_drop_columns($db, $tgt, [$op['col_name']]);
                }
            } catch (\Throwable $e) {
                schema_rebuild_table_drop_columns($db, $tgt, [$op['col_name']]);
            }
            break;

        case 'modify_column':
            // No native ALTER COLUMN in SQLite — rebuild.
            schema_rebuild_table_modify_column($db, $op['table'], $op['col_name'], $op['new_col_def'], $op['src_col']);
            break;

        case 'add_index':
            // Rewrite the source-side index DDL so it lives on target's
            // table with target's prefix in the index name.
            $sql = $op['src_ddl'];
            $old_idx = $op['src_index_name'];
            $new_idx = str_replace($src_prefix, $tgt_prefix, $old_idx);
            $sql = preg_replace(
                '/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
                . preg_quote($old_idx, '/') . '"?/i',
                '$1IF NOT EXISTS "' . $new_idx . '"',
                $sql, 1
            );
            // Rewrite ON "src_table" → ON "tgt_table".
            $sql = preg_replace_callback(
                '/\bON\s+"?(' . preg_quote($src_prefix, '/') . '[A-Za-z0-9_]+)"?\s*\(/i',
                function ($m) use ($src_prefix, $tgt_prefix) {
                    $tgt = str_replace($src_prefix, $tgt_prefix, $m[1]);
                    return 'ON "' . $tgt . '" (';
                },
                $sql, 1
            );
            @$db->exec($sql);
            break;

        case 'drop_index':
            $db->exec('DROP INDEX IF EXISTS "' . SQLite3::escapeString($op['name']) . '"');
            break;
    }
}

/** SQLite-3.34-and-older compatible "drop columns" by full table rebuild.
 *  Also re-creates indexes that don't reference the dropped columns. */
function schema_rebuild_table_drop_columns(SQLite3 $db, string $table, array $drop_cols): void {
    $cols = schema_columns_from_pragma($db, $table);
    $keep = array_values(array_filter($cols, fn($c) => !in_array($c['name'], $drop_cols, true)));
    if (empty($keep)) return;

    $col_names = array_map(fn($c) => '"' . $c['name'] . '"', $keep);
    $col_list  = implode(', ', $col_names);

    // Reuse the original DDL minus the dropped columns. We rebuild via
    // CREATE TABLE … AS SELECT, then re-add an INTEGER PRIMARY KEY by
    // using the existing column types.
    $ddl_parts = [];
    foreach ($keep as $c) {
        $part = '"' . $c['name'] . '" ' . ($c['type'] !== '' ? $c['type'] : 'TEXT');
        if ($c['pk']) $part .= ' PRIMARY KEY';
        if ($c['notnull']) $part .= ' NOT NULL';
        if ($c['dflt_value'] !== null) $part .= ' DEFAULT ' . $c['dflt_value'];
        $ddl_parts[] = $part;
    }
    $tmp_table = $table . '__rebuild_tmp';
    $db->exec('CREATE TABLE "' . SQLite3::escapeString($tmp_table) . '" ('
            . implode(', ', $ddl_parts) . ')');
    $db->exec('INSERT INTO "' . SQLite3::escapeString($tmp_table) . '" '
            . '(' . $col_list . ') SELECT ' . $col_list
            . ' FROM "' . SQLite3::escapeString($table) . '"');
    $db->exec('DROP TABLE "' . SQLite3::escapeString($table) . '"');
    $db->exec('ALTER TABLE "' . SQLite3::escapeString($tmp_table)
            . '" RENAME TO "' . SQLite3::escapeString($table) . '"');
}

/** Rebuild a table to apply a column-type change. Uses src_col's metadata
 *  to know the target type. Other columns retain their definitions. */
function schema_rebuild_table_modify_column(SQLite3 $db, string $table,
                                            string $col_name, string $new_col_def,
                                            array $src_col): void {
    $cols = schema_columns_from_pragma($db, $table);
    $ddl_parts = [];
    $col_names = [];
    foreach ($cols as $c) {
        $col_names[] = '"' . $c['name'] . '"';
        if ($c['name'] === $col_name) {
            // Replace this column's definition with src's.
            $part = $new_col_def;
            // strip out "COLUMN" keyword if present (ADD COLUMN ddl)
            $part = preg_replace('/^\s*COLUMN\s+/i', '', $part);
            $ddl_parts[] = $part;
        } else {
            $part = '"' . $c['name'] . '" ' . ($c['type'] !== '' ? $c['type'] : 'TEXT');
            if ($c['pk']) $part .= ' PRIMARY KEY';
            if ($c['notnull']) $part .= ' NOT NULL';
            if ($c['dflt_value'] !== null) $part .= ' DEFAULT ' . $c['dflt_value'];
            $ddl_parts[] = $part;
        }
    }
    $col_list = implode(', ', $col_names);
    $tmp_table = $table . '__rebuild_tmp';
    $db->exec('CREATE TABLE "' . SQLite3::escapeString($tmp_table) . '" ('
            . implode(', ', $ddl_parts) . ')');
    $db->exec('INSERT INTO "' . SQLite3::escapeString($tmp_table) . '" '
            . '(' . $col_list . ') SELECT ' . $col_list
            . ' FROM "' . SQLite3::escapeString($table) . '"');
    $db->exec('DROP TABLE "' . SQLite3::escapeString($table) . '"');
    $db->exec('ALTER TABLE "' . SQLite3::escapeString($tmp_table)
            . '" RENAME TO "' . SQLite3::escapeString($table) . '"');
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

// Retry the whole file-side transaction on SQLITE_BUSY so a transient
// write lock from a parallel writer doesn't bubble up as a failed merge.
try {
    $applied = sqlite_retry_busy(function() use ($db, $to_apply, $tgt_id, $target) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $n = 0;
            $opcache_paths = [];
            foreach ($to_apply as $path => $entry) {
                if ($entry === null) {
                    $ins = $db->prepare(
                        "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
                      . "VALUES (:b, :p, NULL, 0, :t, 0)"
                    );
                    $ins->bindValue(':b', $tgt_id, SQLITE3_INTEGER);
                    $ins->bindValue(':p', $path, SQLITE3_TEXT);
                    $ins->bindValue(':t', time(), SQLITE3_INTEGER);
                    $ins->execute();
                    $opcache_paths[] = $path;
                    $n++;
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
                $n++;
            }
            foreach ($opcache_paths as $p) {
                opcache_queue_invalidate($db, $target, $p);
            }
            $db->exec('COMMIT');
            return $n;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    });
    echo "  applied: $applied rows\n";
} catch (\Throwable $e) {
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

// Ensure db_snapshots_schema exists. Fork-time schema is the ancestor for
// column-level 3-way diff. Branches that pre-date this table fall back to
// "source's CURRENT schema is the ancestor" (i.e. assume no schema changed
// on the source) — see merge_load_schema_ancestor() below.
$db->exec("CREATE TABLE IF NOT EXISTS db_snapshots_schema (
    branch_id    INTEGER NOT NULL,
    table_name   TEXT NOT NULL,
    ddl_sql      TEXT NOT NULL,
    indexes_json TEXT NOT NULL DEFAULT '[]',
    PRIMARY KEY (branch_id, table_name)
)");

$src_prefix = "b{$src_id}_wp_";
$tgt_prefix = "b{$tgt_id}_wp_";

/** Load (ddl, indexes_array) for the ancestor schema of $tname under
 *  $branch_id. Returns null if no snapshot row exists — caller decides
 *  fallback. */
$merge_load_schema_ancestor = function (SQLite3 $db, int $branch_id, string $tname): ?array {
    $st = $db->prepare("SELECT ddl_sql, indexes_json FROM db_snapshots_schema WHERE branch_id=:b AND table_name=:t");
    $st->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $st->bindValue(':t', $tname, SQLITE3_TEXT);
    $r = $st->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $idx = json_decode((string)$row['indexes_json'], true);
    if (!is_array($idx)) $idx = [];
    return ['ddl' => (string)$row['ddl_sql'], 'indexes' => $idx];
};

// Opportunistic backfill of db_snapshots_schema for any existing branch that
// has live tables but no schema snapshot rows yet. Best-effort: any errors
// here are non-fatal because the merge can still fall back to "current schema
// as ancestor" for branches without a snapshot.
{
    $branches = [];
    $br = $db->query("SELECT id FROM branches");
    while ($brow = $br->fetchArray(SQLITE3_NUM)) $branches[] = (int)$brow[0];
    foreach ($branches as $bid) {
        $has_any = (int)$db->querySingle(
            "SELECT COUNT(*) FROM db_snapshots_schema WHERE branch_id = $bid"
        );
        if ($has_any > 0) continue;
        $bprefix = "b{$bid}_wp_";
        $tnames = [];
        $tr = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
            . SQLite3::escapeString($bprefix) . "%'"
        );
        while ($trow = $tr->fetchArray(SQLITE3_NUM)) $tnames[] = $trow[0];
        if (empty($tnames)) continue;
        try {
            $st = $db->prepare(
                "INSERT OR REPLACE INTO db_snapshots_schema "
              . "(branch_id, table_name, ddl_sql, indexes_json) "
              . "VALUES (:b, :t, :d, :i)"
            );
            foreach ($tnames as $tname) {
                $ddl = (string)$db->querySingle(
                    "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
                    . SQLite3::escapeString($tname) . "'"
                );
                $idxs = [];
                $ir = $db->query(
                    "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='"
                    . SQLite3::escapeString($tname) . "' AND sql IS NOT NULL"
                );
                while ($irow = $ir->fetchArray(SQLITE3_NUM)) $idxs[] = $irow[0];
                $st->bindValue(':b', $bid, SQLITE3_INTEGER);
                $st->bindValue(':t', $tname, SQLITE3_TEXT);
                $st->bindValue(':d', $ddl, SQLITE3_TEXT);
                $st->bindValue(':i', json_encode($idxs, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                $st->execute();
                $st->reset();
            }
        } catch (\Throwable $e) {
            // Non-fatal — merge will still work via the current-schema fallback.
        }
    }
}

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

// --on-id-collision=renumber state:
//   $renumber_map[$suffix] = [ $old_id => $new_id, ... ]
//   $renumber_log          = [ ['suffix', 'pk_col', $old, $new], ... ]
//   $renumber_next_id      = per-suffix next free ID, pre-allocated by inspecting
//                            MAX(src_pk), MAX(tgt_pk) at the start of each table walk.
$renumber_map     = [];
$renumber_log     = [];
$renumber_next_id = [];
$FK_MAP           = merge_fk_map();

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
        // ── Schema-level 3-way diff (must run BEFORE row-level so any
        // ADD COLUMN happens before we INSERT a source row into target).
        $src_cols = schema_columns_from_pragma($db, $src_tname);
        $tgt_cols = schema_columns_from_pragma($db, $tgt_tname);
        $src_idxs = schema_indexes_from_master($db, $src_tname);
        $tgt_idxs = schema_indexes_from_master($db, $tgt_tname);

        $src_ddl_now = (string)$db->querySingle(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
            . SQLite3::escapeString($src_tname) . "'"
        );

        $anc_schema = $merge_load_schema_ancestor($db, $src_id, $src_tname);
        if ($anc_schema === null) {
            // Backward compat: no schema snapshot for this branch (legacy
            // branch created before db_snapshots_schema existed). Treat
            // source's CURRENT schema as the ancestor — i.e. assume no
            // schema change happened on source.
            $anc_cols = $src_cols;
            $anc_idxs = $src_idxs;
        } else {
            $anc_cols = schema_columns_from_ddl($anc_schema['ddl'], $src_tname);
            $anc_idxs = [];
            foreach ($anc_schema['indexes'] as $isql) {
                if (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?"?([^"\s(]+)"?/i', $isql, $im)) {
                    $anc_idxs[] = ['name' => $im[1], 'sql' => $isql];
                }
            }
        }

        $sd = schema_diff_table(
            $anc_cols, $anc_idxs,
            $src_cols, $src_idxs,
            $tgt_cols, $tgt_idxs,
            $src_prefix, $tgt_prefix,
            $src_ddl_now, $tgt_tname
        );
        foreach ($sd['ops'] as $sop) {
            $db_clean_ops[] = ['type' => 'schema', 'op' => $sop];
        }
        foreach ($sd['conflicts'] as $sc) {
            $db_conflict_ops[] = [
                'desc'      => $sc['desc'],
                'ours_op'   => $sc['ours_op'] !== null
                                ? ['type' => 'schema', 'op' => $sc['ours_op']]
                                : null,
                'theirs_op' => $sc['theirs_op'] !== null
                                ? ['type' => 'schema', 'op' => $sc['theirs_op']]
                                : null,
            ];
        }

        $src_pk   = db_pk_cols($db, $src_tname);
        $tgt_pk   = db_pk_cols($db, $tgt_tname);
        $src_rows = db_table_rows($db, $src_tname, $src_pk);
        $tgt_rows = db_table_rows($db, $tgt_tname, $tgt_pk);
        $anc_rows = $in_anc ? db_ancestor_rows($db, $src_id, $anc_tname) : [];

        $all_pks = array_unique(array_merge(
            array_keys($src_rows), array_keys($tgt_rows), array_keys($anc_rows)
        ));

        // Renumber eligibility for this table (suffix): only auto-inc single
        // INTEGER PK tables whose suffix is in $FK_MAP.
        $renumber_eligible =
            $on_id_collision === 'renumber'
            && count($src_pk) === 1
            && isset($FK_MAP[$suffix])
            && merge_table_is_autoinc_pk($db, $src_tname)
            && merge_table_is_autoinc_pk($db, $tgt_tname);

        if ($renumber_eligible && !isset($renumber_next_id[$suffix])) {
            $pk_col = $src_pk[0];
            $smax = merge_table_max_pk($db, $src_tname, $pk_col);
            $tmax = merge_table_max_pk($db, $tgt_tname, $pk_col);
            $renumber_next_id[$suffix] = max($smax, $tmax) + 1;
        }

        foreach ($all_pks as $pk) {
            $a = $anc_rows[$pk] ?? null;
            $s = $src_rows[$pk] ?? null;
            $t = $tgt_rows[$pk] ?? null;

            if ($s === $t) { $db_noop++; continue; }

            if ($a === null) {
                if ($s !== null && $t === null) {
                    $db_clean_ops[] = ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                       'suffix' => $suffix];
                    $db_inserted++;
                } elseif ($s !== null && $t !== null) {
                    // Collision: both sides independently inserted different
                    // rows with the same PK. If renumber is requested AND the
                    // table is autoinc + in the FK map, allocate a new PK for
                    // source's row and queue an upsert with the rewritten PK.
                    if ($renumber_eligible) {
                        $pk_col = $src_pk[0];
                        $pk_map = json_decode($pk, true);
                        $old_id = isset($pk_map[$pk_col]) ? (int)$pk_map[$pk_col] : 0;
                        if ($old_id > 0) {
                            $new_id = $renumber_next_id[$suffix]++;
                            $renumber_map[$suffix][$old_id] = $new_id;
                            $renumber_log[] = [$tgt_tname, $pk_col, $old_id, $new_id];
                            $renumbered_row = merge_row_with_pk($s, $pk_col, $new_id);
                            $db_clean_ops[] = [
                                'type'     => 'upsert',
                                'table'    => $tgt_tname,
                                'row_json' => $renumbered_row,
                                'suffix'   => $suffix,
                            ];
                            $db_inserted++;
                            continue;
                        }
                    }
                    $db_conflict_ops[] = [
                        'desc'      => "$tgt_tname pk=$pk (both inserted different rows)",
                        'ours_op'   => null,
                        'theirs_op' => ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                        'suffix' => $suffix],
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
                $db_clean_ops[] = ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                   'suffix' => $suffix];
                $db_updated++;
            } else {
                $db_conflict_ops[] = [
                    'desc'      => "$tgt_tname pk=$pk (both modified)",
                    'ours_op'   => null,
                    'theirs_op' => ['type' => 'upsert', 'table' => $tgt_tname, 'row_json' => $s,
                                    'suffix' => $suffix],
                ];
            }
        }
    }
}

$schema_op_count = 0;
foreach ($db_clean_ops as $cop) {
    if (($cop['type'] ?? '') === 'schema') $schema_op_count++;
}

echo "  tables considered: " . count($all_suffixes) . "\n";
echo "  row no-op:         $db_noop\n";
echo "  row inserts:       $db_inserted\n";
echo "  row updates:       $db_updated\n";
echo "  row deletes:       $db_deleted\n";
echo "  schema ops:        $schema_op_count\n";
echo "  id renumbers:      " . count($renumber_log) . "\n";
echo "  conflicts:         " . count($db_conflict_ops) . "\n";

if (!empty($renumber_log)) {
    echo "\nID collision renumbers (" . count($renumber_log) . "):\n";
    foreach ($renumber_log as $entry) {
        [$tname, $pk_col, $old_id, $new_id] = $entry;
        echo "  " . str_pad($tname . '.' . $pk_col, 36) . " $old_id -> $new_id\n";
    }
    echo "\n";
}

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

// Pass 2 of the two-pass renumber: rewrite FK columns in source-origin rows
// that are about to be upserted into FK-bearing tables. For a renumbered
// wp_posts.ID 42 → 157, source's wp_postmeta rows with post_id=42 now need
// post_id=157 before the upsert — otherwise they'd either overwrite target's
// unrelated meta for post 42 or float free.
//
// Critical scoping: only rewrite source-origin upserts. Target-origin rows
// never collide on the renumbered IDs (we just picked IDs beyond both sides'
// max), so rewriting their FK columns is a no-op in practice — but we only
// have source-origin rows in $final_db_ops for upsert anyway, since the
// row_level walk either keeps target unchanged (noop) or upserts source's
// row_json. So it's safe to walk every upsert here.
if (!empty($renumber_map)) {
    // Build: fk_table_suffix => [ [fk_col, map_ref], ... ]
    $fk_rewrites_by_suffix = [];
    foreach ($renumber_map as $pk_suffix => $map) {
        if (empty($map)) continue;
        if (!isset($FK_MAP[$pk_suffix])) continue;
        foreach ($FK_MAP[$pk_suffix] as $fk_entry) {
            [$fk_table_suffix, $fk_col] = $fk_entry;
            $fk_rewrites_by_suffix[$fk_table_suffix][] = [$fk_col, $map];
        }
    }
    foreach ($final_db_ops as &$op) {
        if ($op['type'] !== 'upsert') continue;
        $sfx = $op['suffix'] ?? null;
        if ($sfx === null) continue;
        if (!isset($fk_rewrites_by_suffix[$sfx])) continue;
        $op['row_json'] = merge_rewrite_row_fks($op['row_json'], $fk_rewrites_by_suffix[$sfx]);
    }
    unset($op);
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
// Sort schema ops to the front so column ADD/DROP/MODIFY happens before any
// row INSERT into the (possibly new-shaped) target table.
usort($final_db_ops, function($a, $b) {
    $rank = function($op) {
        if ($op['type'] === 'schema') {
            // Among schema ops: drop_index → modify/drop column → add column → add_index
            $sub = $op['op']['type'] ?? '';
            return match ($sub) {
                'drop_index'    => 0,
                'drop_column'   => 1,
                'modify_column' => 2,
                'add_column'    => 3,
                'add_index'     => 4,
                default         => 5,
            };
        }
        if ($op['type'] === 'new_table') return 6;
        if ($op['type'] === 'drop_table') return 7;
        return 8; // upsert / delete (row-level) last
    };
    return $rank($a) <=> $rank($b);
});

try {
    [$db_applied, $snap_total, $schema_total] = sqlite_retry_busy(
        function() use ($db, $final_db_ops, $src_id, $src_prefix, $tgt_prefix) {
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
                            $db->exec('DROP TABLE IF EXISTS "'
                                . SQLite3::escapeString($op['tgt']) . '"');
                            break;
                        case 'schema':
                            schema_apply_op($db, $op['op'], $src_prefix, $tgt_prefix);
                            break;
                    }
                    $db_applied++;
                }

                // Refresh source branch's ancestor snapshot (rows + schema).
                $db->exec('DELETE FROM db_snapshots WHERE branch_id = ' . (int)$src_id);
                $db->exec('DELETE FROM db_snapshots_schema WHERE branch_id = ' . (int)$src_id);

                $snap_ins = $db->prepare(
                    "INSERT OR REPLACE INTO db_snapshots (branch_id, table_name, row_pk, row_json) "
                  . "VALUES (:bid, :tname, :rpk, :rjson)"
                );
                $schema_ins = $db->prepare(
                    "INSERT OR REPLACE INTO db_snapshots_schema "
                  . "(branch_id, table_name, ddl_sql, indexes_json) "
                  . "VALUES (:bid, :tname, :ddl, :idx)"
                );

                $snap_tables = [];
                $tr = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
                    . SQLite3::escapeString($src_prefix) . "%'");
                while ($row = $tr->fetchArray(SQLITE3_NUM)) {
                    $snap_tables[] = $row[0];
                }

                $snap_total = 0;
                $schema_total = 0;
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

                    // Snapshot the (possibly modified) source schema too.
                    $ddl = (string)$db->querySingle(
                        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
                        . SQLite3::escapeString($tname) . "'"
                    );
                    $idxs = [];
                    $ir = $db->query(
                        "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='"
                        . SQLite3::escapeString($tname) . "' AND sql IS NOT NULL"
                    );
                    while ($irow = $ir->fetchArray(SQLITE3_NUM)) $idxs[] = $irow[0];
                    $schema_ins->bindValue(':bid',   $src_id, SQLITE3_INTEGER);
                    $schema_ins->bindValue(':tname', $tname,  SQLITE3_TEXT);
                    $schema_ins->bindValue(':ddl',   $ddl,    SQLITE3_TEXT);
                    $schema_ins->bindValue(':idx',   json_encode($idxs, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
                    $schema_ins->execute();
                    $schema_ins->reset();
                    $schema_total++;
                }

                $db->exec('COMMIT');
                return [$db_applied, $snap_total, $schema_total];
            } catch (\Throwable $e) {
                $db->exec('ROLLBACK');
                throw $e;
            }
        }
    );
    echo "  DB ops applied: $db_applied\n";
    echo "  refreshed ancestor snapshot for '$source': $snap_total rows, $schema_total tables\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "merge: DB phase failed: " . $e->getMessage() . "\n");
    $db->close();
    exit(4);
}

$db->close();
echo "\nMerge complete.\n";
