<?php
/**
 * COW (Copy-on-Write) DB branch helpers — shared between branchctl.php
 * and merge.php.
 *
 * Don't include this file from CLI scripts directly — `require_once`
 * from branchctl.php and merge.php instead.
 */

// ============================================================
// COW (Copy-on-Write) DB branch helpers
// ============================================================
//
// Each "logical" WordPress table on a non-main branch is replaced with:
//
//   b{id}_wp_X                  →  VIEW (what WordPress queries)
//   b{id}_wp_X__overlay         →  TABLE (rows the branch added/modified)
//   b{id}_wp_X__tombstones      →  TABLE (PKs the branch deleted from inherited rows)
//
// The view returns:
//   SELECT * FROM overlay
//   UNION ALL
//   SELECT * FROM <parent's view> p
//   WHERE (p.pk_cols) NOT IN (SELECT pk_cols FROM overlay)
//     AND (p.pk_cols) NOT IN (SELECT pk_cols FROM tombstones)
//
// Branch creation is now O(num_tables × small constant) — no row data
// is touched. For ~22 typical WP tables, branch create is milliseconds
// regardless of row count.

/** Return ordered PRIMARY KEY column names for a table or view.
 *
 *  `PRAGMA table_info()` on a VIEW returns rows with pk=0 for every column —
 *  SQLite doesn't propagate the underlying PK through the view metadata.
 *  When the target is a COW branch view we recurse into its __overlay (which
 *  carries the same shape and the original PK) to recover the true PK set.
 *  Fallback all the way up to the parent table if no __overlay exists yet.
 *
 *  Empty array if the table has no explicit PK. */
function cow_extract_pk_cols(SQLite3 $db, string $real_table_name): array {
    $target = $real_table_name;
    // If the target is a view, try to find an underlying __overlay table to
    // get PK metadata from. Walk up through the COW marker chain.
    $guard = 0;
    while (cow_is_view($db, $target) && $guard++ < 32) {
        $overlay = $target . '__overlay';
        if (cow_is_table($db, $overlay)) {
            $target = $overlay;
            break;
        }
        // Otherwise walk up to the parent's logical table via the COW marker.
        // e.g. target = "b3_wp_options": find branch id 3, its parent, then
        // parent's logical "b{parent_id}_wp_options".
        if (!preg_match('/^b(\d+)_wp_(.+)$/', $target, $m)) break;
        $bid = (int)$m[1];
        $suffix = $m[2];
        $pid = (int)$db->querySingle(
            "SELECT parent_branch_id FROM db_cow_branches "
          . "WHERE branch_id=$bid AND table_suffix='" . SQLite3::escapeString($suffix) . "'"
        );
        if ($pid <= 0) break;
        $target = "b{$pid}_wp_{$suffix}";
    }
    $pk = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($target) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        if ((int)$row['pk'] > 0) $pk[(int)$row['pk']] = $row['name'];
    }
    ksort($pk);
    return array_values($pk);
}

/** Return ordered list of all column names for a table. */
function cow_table_columns(SQLite3 $db, string $real_table_name): array {
    $cols = [];
    $r = $db->query('PRAGMA table_info("' . SQLite3::escapeString($real_table_name) . '")');
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = (string)$row['name'];
    }
    return $cols;
}

/** Resolve a branch name to its parent branch's id (0 if it has no parent
 *  or parent doesn't exist). For 'main' returns 0. */
function cow_parent_branch_id(SQLite3 $db, int $branch_id): int {
    $s = $db->prepare(
        "SELECT b2.id FROM branches b1 JOIN branches b2 "
      . "ON b1.parent_branch = b2.name WHERE b1.id = :b"
    );
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return $row ? (int)$row[0] : 0;
}

/** True iff the named object exists and is a view. */
function cow_is_view(SQLite3 $db, string $name): bool {
    $t = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($name) . "'"
    );
    return $t === 'view';
}

/** True iff the named object exists and is a table. */
function cow_is_table(SQLite3 $db, string $name): bool {
    $t = (string)$db->querySingle(
        "SELECT type FROM sqlite_master WHERE name='" . SQLite3::escapeString($name) . "'"
    );
    return $t === 'table';
}

/** Build the SELECT body of the view (no CREATE VIEW prefix).
 *  $parent_view_name is the name of the parent's logical table — could be
 *  a real table (if parent is main) or a view (if parent is itself a branch). */
function cow_resolve_view_sql(string $overlay_name, string $tombstone_name,
                              string $parent_view_name, array $pk_cols,
                              array $columns): string {
    $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
    if (empty($pk_cols)) {
        // No explicit PK: just UNION ALL (no dedup possible). Tombstones
        // can't really apply either; fall back to overlay-only when
        // tombstone is non-empty would be safest, but for WordPress
        // every table has a PK so this branch is essentially never hit.
        return "SELECT $col_list FROM \"$overlay_name\" "
             . "UNION ALL "
             . "SELECT $col_list FROM \"$parent_view_name\"";
    }
    if (count($pk_cols) === 1) {
        $pk = '"' . $pk_cols[0] . '"';
        return "SELECT $col_list FROM \"$overlay_name\" "
             . "UNION ALL "
             . "SELECT $col_list FROM \"$parent_view_name\" p "
             . "WHERE p.$pk NOT IN (SELECT $pk FROM \"$overlay_name\") "
             . "  AND p.$pk NOT IN (SELECT $pk FROM \"$tombstone_name\")";
    }
    // Composite PK: use row-value tuples.
    $pk_tuple_p   = '(' . implode(', ', array_map(fn($c) => 'p."' . $c . '"', $pk_cols)) . ')';
    $pk_tuple_sel = '(' . implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols))   . ')';
    $pk_sel       = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
    return "SELECT $col_list FROM \"$overlay_name\" "
         . "UNION ALL "
         . "SELECT $col_list FROM \"$parent_view_name\" p "
         . "WHERE $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$overlay_name\") "
         . "  AND $pk_tuple_p NOT IN (SELECT $pk_sel FROM \"$tombstone_name\")";
}

/** Build a JSON-object expression `json_object('col1', SRC.col1, ...)` for
 *  a list of columns referenced via SRC alias (typically OLD or NEW). */
function cow_json_obj_expr(string $src_alias, array $columns): string {
    $pairs = [];
    foreach ($columns as $c) {
        $pairs[] = "'" . str_replace("'", "''", $c) . "', $src_alias.\"$c\"";
    }
    return 'json_object(' . implode(', ', $pairs) . ')';
}

/** Install (or reinstall) the parent-side BEFORE UPDATE/DELETE triggers
 *  that capture the old row into db_ancestor_overlay for every COW
 *  descendant of $parent_table.
 *
 *  Uses a single trigger per parent table that reads db_cow_branches at
 *  fire time, so adding more descendants doesn't require redoing the
 *  trigger. Trigger names are derived from the parent table name.
 *
 *  Only installs on REAL tables (BEFORE/AFTER triggers on tables); views
 *  use INSTEAD OF triggers which conflict with our overlay-routing
 *  triggers. For nested-branch parents, ancestor capture falls back to
 *  the parent-current approximation in db_ancestor_rows_cow(). */
function cow_install_parent_triggers(SQLite3 $db, string $parent_table): void {
    if (!cow_is_table($db, $parent_table)) return;

    // Discover the parent's PK + columns (used to build OLD/NEW projections).
    $pk_cols = cow_extract_pk_cols($db, $parent_table);
    $columns = cow_table_columns($db, $parent_table);
    if (empty($columns)) return;

    // Use only PK cols for the row_pk JSON; full columns for row_json.
    if (empty($pk_cols)) {
        $pk_for_obj = $columns; // degenerate; we don't really support PK-less tables in COW
    } else {
        $pk_for_obj = $pk_cols;
    }

    $pk_obj_expr  = cow_json_obj_expr('OLD', $pk_for_obj);
    $row_obj_expr = cow_json_obj_expr('OLD', $columns);
    $pk_obj_expr_new = cow_json_obj_expr('NEW', $pk_for_obj);

    $trg_upd = "cow_anc__" . preg_replace('/[^a-zA-Z0-9_]/', '_', $parent_table) . "__upd";
    $trg_del = "cow_anc__" . preg_replace('/[^a-zA-Z0-9_]/', '_', $parent_table) . "__del";
    $trg_ins = "cow_anc__" . preg_replace('/[^a-zA-Z0-9_]/', '_', $parent_table) . "__ins";

    $db->exec("DROP TRIGGER IF EXISTS \"$trg_upd\"");
    $db->exec("DROP TRIGGER IF EXISTS \"$trg_del\"");
    $db->exec("DROP TRIGGER IF EXISTS \"$trg_ins\"");

    // We use BEFORE so OLD reflects the row about to change.
    // INSERT OR IGNORE preserves the FIRST pre-divergence value as the
    // true fork-time ancestor for the branch (subsequent parent edits
    // don't overwrite it).
    //
    // We restrict the inner SELECT to the descendant branches whose
    // db_cow_branches.parent_table_name matches THIS parent table, so a
    // shared trigger can serve multiple descendants without growing the
    // table-name list inside the trigger SQL.
    $upd_trigger_sql = "CREATE TRIGGER \"$trg_upd\" BEFORE UPDATE ON \"$parent_table\"\n"
                     . "BEGIN\n"
                     . "    INSERT OR IGNORE INTO db_ancestor_overlay\n"
                     . "        (branch_id, table_name, row_pk, row_json)\n"
                     . "    SELECT cb.branch_id,\n"
                     . "           'b' || cb.branch_id || '_wp_' || cb.table_suffix,\n"
                     . "           $pk_obj_expr,\n"
                     . "           $row_obj_expr\n"
                     . "    FROM db_cow_branches cb\n"
                     . "    WHERE cb.parent_table_name = '" . SQLite3::escapeString($parent_table) . "';\n"
                     . "END";
    $db->exec($upd_trigger_sql);

    $del_trigger_sql = "CREATE TRIGGER \"$trg_del\" BEFORE DELETE ON \"$parent_table\"\n"
                     . "BEGIN\n"
                     . "    INSERT OR IGNORE INTO db_ancestor_overlay\n"
                     . "        (branch_id, table_name, row_pk, row_json)\n"
                     . "    SELECT cb.branch_id,\n"
                     . "           'b' || cb.branch_id || '_wp_' || cb.table_suffix,\n"
                     . "           $pk_obj_expr,\n"
                     . "           $row_obj_expr\n"
                     . "    FROM db_cow_branches cb\n"
                     . "    WHERE cb.parent_table_name = '" . SQLite3::escapeString($parent_table) . "';\n"
                     . "END";
    $db->exec($del_trigger_sql);

    // AFTER INSERT: track the PK as "post-fork inserted on parent" so the
    // merge can distinguish "ancestor was absent" (this case) from
    // "ancestor was the same as target's current row".
    $ins_trigger_sql = "CREATE TRIGGER \"$trg_ins\" AFTER INSERT ON \"$parent_table\"\n"
                     . "BEGIN\n"
                     . "    INSERT OR IGNORE INTO db_post_fork_inserts\n"
                     . "        (branch_id, table_name, row_pk)\n"
                     . "    SELECT cb.branch_id,\n"
                     . "           'b' || cb.branch_id || '_wp_' || cb.table_suffix,\n"
                     . "           $pk_obj_expr_new\n"
                     . "    FROM db_cow_branches cb\n"
                     . "    WHERE cb.parent_table_name = '" . SQLite3::escapeString($parent_table) . "';\n"
                     . "END";
    $db->exec($ins_trigger_sql);
}

/** Generate the INSTEAD OF triggers for the view that route writes to
 *  overlay/tombstones.
 *
 *  $columns is the ordered column list. $defaults (optional) is a map of
 *  col_name => default_value_expr — for columns with NOT NULL DEFAULT,
 *  the trigger uses COALESCE(NEW.col, default) so an INSERT that omits
 *  the column gets the same default the underlying table would. */
function cow_trigger_sql(string $view_name, string $overlay_name,
                        string $tombstone_name, array $pk_cols,
                        array $columns, array $defaults = []): array {
    $col_list   = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
    $new_vals_with_default = function (string $c) use ($defaults): string {
        if (isset($defaults[$c]) && $defaults[$c] !== null && $defaults[$c] !== '') {
            return 'COALESCE(NEW."' . $c . '", ' . $defaults[$c] . ')';
        }
        return 'NEW."' . $c . '"';
    };
    $new_vals   = implode(', ', array_map($new_vals_with_default, $columns));
    $set_list   = implode(', ', array_map(fn($c) => '"' . $c . '" = NEW."' . $c . '"', $columns));

    // PK match condition for tombstone delete (after re-insert) and overlay
    // upsert/delete using OLD/NEW.
    if (empty($pk_cols)) {
        // Fallback: identity-by-rowid is unsafe across UNION ALL. Match by
        // every column. Triggers without a PK aren't ideal but are rare
        // for WP tables — we still need OLD-vs-NEW separation so the
        // INSTEAD OF INSERT trigger uses NEW (OLD doesn't exist on INSERT).
        $pk_match_old = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS OLD."' . $c . '"', $columns
        ));
        $pk_match_new = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS NEW."' . $c . '"', $columns
        ));
        $pk_cols_for_tomb = $columns;
        $tomb_old_vals = implode(', ', array_map(fn($c) => 'OLD."' . $c . '"', $columns));
    } else {
        $pk_match_old = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS OLD."' . $c . '"', $pk_cols
        ));
        $pk_match_new = implode(' AND ', array_map(
            fn($c) => '"' . $c . '" IS NEW."' . $c . '"', $pk_cols
        ));
        $pk_cols_for_tomb = $pk_cols;
        $tomb_old_vals = implode(', ', array_map(fn($c) => 'OLD."' . $c . '"', $pk_cols));
    }
    $tomb_cols = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols_for_tomb));

    $ins_trg = "CREATE TRIGGER \"{$view_name}__cow_ins\" INSTEAD OF INSERT ON \"$view_name\"\n"
             . "BEGIN\n"
             // Clear any tombstone for this PK (re-insert after delete).
             . "    DELETE FROM \"$tombstone_name\" WHERE $pk_match_new;\n"
             // Plain INSERT so UNIQUE / PK violations propagate up to the
             // caller (matches the behaviour of inserting into a real table).
             . "    INSERT INTO \"$overlay_name\" ($col_list) VALUES ($new_vals);\n"
             . "END";

    $upd_trg = "CREATE TRIGGER \"{$view_name}__cow_upd\" INSTEAD OF UPDATE ON \"$view_name\"\n"
             . "BEGIN\n"
             . "    DELETE FROM \"$tombstone_name\" WHERE $pk_match_old;\n"
             . "    INSERT OR REPLACE INTO \"$overlay_name\" ($col_list) VALUES ($new_vals);\n"
             . "END";

    // DELETE: if row is in overlay, remove from overlay; ALSO mark as
    // tombstone so any inherited row is hidden. (If the row was branch-only
    // and not inherited, the tombstone is harmless — it just shadows nothing.)
    $del_trg = "CREATE TRIGGER \"{$view_name}__cow_del\" INSTEAD OF DELETE ON \"$view_name\"\n"
             . "BEGIN\n"
             . "    DELETE FROM \"$overlay_name\" WHERE $pk_match_old;\n"
             . "    INSERT OR REPLACE INTO \"$tombstone_name\" ($tomb_cols) VALUES ($tomb_old_vals);\n"
             . "END";

    return [$ins_trg, $upd_trg, $del_trg];
}

/** Resolve, for a branch_id, the name of the "logical" object (table or
 *  view) that exposes table_suffix on that branch. For main, this is the
 *  real table b1_wp_<suffix>; for any other branch this is the view
 *  b{id}_wp_<suffix>. */
function cow_logical_name(int $branch_id, string $table_suffix): string {
    return "b{$branch_id}_wp_{$table_suffix}";
}

/** Create the COW trio (overlay + tombstones + view + INSTEAD OF triggers)
 *  for one table on a new branch. Replicates indexes (notably UNIQUE) onto
 *  the overlay. Copies the parent's sqlite_sequence row so autoincrement
 *  IDs from the branch don't collide with the parent. */
function cow_create_branch_table(SQLite3 $db, int $branch_id, int $parent_id,
                                 string $table_suffix): void {
    $parent_table = "b{$parent_id}_wp_{$table_suffix}";
    $logical_name = "b{$branch_id}_wp_{$table_suffix}";
    $overlay_name = $logical_name . '__overlay';
    $tomb_name    = $logical_name . '__tombstones';

    // Discover the parent's underlying physical table for column/PK metadata.
    // PRAGMA table_info() is happy to describe a view as well as a table —
    // when the parent is itself a branch (so $parent_table is a view), this
    // still returns the correct columns and PK info as inherited.
    $pk_cols = cow_extract_pk_cols($db, $parent_table);
    $columns = cow_table_columns($db, $parent_table);

    if (empty($columns)) {
        // No table to mirror. Skip silently — caller will warn.
        return;
    }

    // Build overlay DDL by reusing parent's CREATE TABLE statement when
    // available (so PRIMARY KEY / UNIQUE / NOT NULL / DEFAULTs are preserved).
    // If the parent is a VIEW (i.e. parent is itself a branch), we walk up
    // to a real underlying overlay or table to get a DDL we can clone.
    $ddl_source = $parent_table;
    while (cow_is_view($db, $ddl_source)) {
        // Use the parent branch's overlay as DDL source — it has the same
        // shape as the logical view.
        $cow_pid = (int)$db->querySingle(
            "SELECT parent_branch_id FROM db_cow_branches "
          . "WHERE branch_id IN (SELECT id FROM branches WHERE name=("
          . "    SELECT parent_branch FROM branches WHERE id=$parent_id"
          . ")) LIMIT 1"
        );
        // Try the parent's overlay first; if it doesn't exist, fall back to
        // the parent of the parent's logical table.
        $cand_overlay = $ddl_source . '__overlay';
        if (cow_is_table($db, $cand_overlay)) {
            $ddl_source = $cand_overlay;
        } else {
            // Walk further up the parent chain.
            $bid = (int)$db->querySingle(
                "SELECT id FROM branches WHERE name = (SELECT parent_branch FROM branches WHERE id = $parent_id)"
            );
            if ($bid <= 0) break;
            $ddl_source = "b{$bid}_wp_{$table_suffix}";
        }
    }
    $parent_ddl = (string)$db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($ddl_source) . "'"
    );

    // Build overlay table.
    if ($parent_ddl !== '') {
        $overlay_ddl = preg_replace(
            '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($ddl_source, '/') . '"?(\s*\()/is',
            '$1IF NOT EXISTS "' . $overlay_name . '"$2',
            $parent_ddl, 1
        );
        $db->exec($overlay_ddl);
    } else {
        // Last-ditch fallback: copy via SELECT (loses constraints).
        $db->exec("CREATE TABLE IF NOT EXISTS \"$overlay_name\" AS "
                . "SELECT * FROM \"$ddl_source\" WHERE 0");
    }

    // Tombstone table: just the PK columns. Preserve types where we can.
    if (!empty($pk_cols)) {
        // Discover types for the PK cols from PRAGMA on the parent.
        $pk_types = [];
        $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($parent_table) . '")');
        while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
            if (in_array($prow['name'], $pk_cols, true)) {
                $pk_types[$prow['name']] = $prow['type'] ?: 'TEXT';
            }
        }
        $tomb_cols_ddl = [];
        foreach ($pk_cols as $c) {
            $t = $pk_types[$c] ?? 'TEXT';
            $tomb_cols_ddl[] = '"' . $c . '" ' . $t;
        }
        $tomb_pk = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
        $db->exec(
            "CREATE TABLE IF NOT EXISTS \"$tomb_name\" ("
          . implode(', ', $tomb_cols_ddl)
          . ", PRIMARY KEY ($tomb_pk))"
        );
    } else {
        // No PK on parent: tombstone is by full-row identity. Mirror columns.
        $cols_ddl = [];
        foreach ($columns as $c) $cols_ddl[] = '"' . $c . '" BLOB';
        $db->exec("CREATE TABLE IF NOT EXISTS \"$tomb_name\" ("
                . implode(', ', $cols_ddl) . ")");
    }

    // Replicate non-PK UNIQUE indexes from the parent onto the overlay so
    // duplicate-insert semantics match. Skip auto-indexes (sql IS NULL).
    $idx_stmt = $db->prepare(
        "SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=:t "
      . "AND sql IS NOT NULL"
    );
    $idx_stmt->bindValue(':t', $ddl_source, SQLITE3_TEXT);
    $idx_res  = $idx_stmt->execute();
    $idx_rows = [];
    while ($irow = $idx_res->fetchArray(SQLITE3_ASSOC)) $idx_rows[] = $irow;
    $idx_res->finalize();
    $idx_stmt->close();
    // Determine parent's own prefix so we can do a clean str_replace from
    // the parent's index name to a branch-local name.
    $parent_prefix = "b{$parent_id}_wp_";
    $branch_prefix = "b{$branch_id}_wp_";
    foreach ($idx_rows as $irow) {
        $old_idx_name = $irow['name'];
        // Rename the index by str_replace'ing the parent's prefix for the
        // branch's. This both yields a unique-per-branch name AND keeps
        // the schema_merge normalization (which strips the prefix) producing
        // the SAME normalized key for parent-side and branch-side indexes
        // — so adding/dropping indexes on the branch shows up cleanly in
        // the schema diff.
        $new_idx_name = str_replace($parent_prefix, $branch_prefix, $old_idx_name);
        if ($new_idx_name === $old_idx_name) {
            // Index name didn't embed parent prefix — prefix it ourselves
            // to avoid collision with the parent's identically-named index.
            $new_idx_name = $branch_prefix . $old_idx_name;
        }
        $idx_sql = preg_replace(
            '/^(CREATE\s+(?:UNIQUE\s+)?INDEX\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
            . preg_quote($old_idx_name, '/') . '"?/i',
            '$1IF NOT EXISTS "' . $new_idx_name . '"',
            $irow['sql'], 1
        );
        $idx_sql = preg_replace(
            '/\bON\s+"?' . preg_quote($ddl_source, '/') . '"?\s*\(/i',
            'ON "' . $overlay_name . '" (',
            $idx_sql, 1
        );
        @$db->exec($idx_sql);
    }

    // Copy parent's sqlite_sequence row so branch INSERTs don't collide.
    // (Only meaningful for AUTOINCREMENT tables, but harmless for others.)
    $seq_max_parent = (int)$db->querySingle(
        "SELECT seq FROM sqlite_sequence WHERE name='"
        . SQLite3::escapeString($ddl_source) . "'"
    );
    if ($seq_max_parent > 0) {
        $db->exec("INSERT OR REPLACE INTO sqlite_sequence (name, seq) "
                . "VALUES ('" . SQLite3::escapeString($overlay_name) . "', $seq_max_parent)");
    }

    // Create view + triggers.
    $view_sql_body = cow_resolve_view_sql(
        $overlay_name, $tomb_name, $parent_table, $pk_cols, $columns
    );
    $db->exec("CREATE VIEW IF NOT EXISTS \"$logical_name\" AS $view_sql_body");
    // Capture per-column defaults from the overlay so the trigger can
    // COALESCE(NEW.col, default) when the user omits a NOT NULL column.
    $defaults = [];
    $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($overlay_name) . '")');
    while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
        if ($prow['dflt_value'] !== null) {
            $defaults[$prow['name']] = (string)$prow['dflt_value'];
        }
    }
    foreach (cow_trigger_sql($logical_name, $overlay_name, $tomb_name, $pk_cols, $columns, $defaults) as $trg) {
        $db->exec($trg);
    }

    // Record the COW marker so merge / introspection can detect format.
    $tok = (string)$db->querySingle(
        "SELECT COALESCE(MAX(rowid), 0) FROM \"" . SQLite3::escapeString($parent_table) . "\""
    );
    $st = $db->prepare(
        "INSERT OR REPLACE INTO db_cow_branches "
      . "(branch_id, table_suffix, parent_branch_id, parent_table_name, fork_token) "
      . "VALUES (:b, :s, :p, :pt, :t)"
    );
    $st->bindValue(':b',  $branch_id,    SQLITE3_INTEGER);
    $st->bindValue(':s',  $table_suffix, SQLITE3_TEXT);
    $st->bindValue(':p',  $parent_id,    SQLITE3_INTEGER);
    $st->bindValue(':pt', $parent_table, SQLITE3_TEXT);
    $st->bindValue(':t',  (string)$tok,  SQLITE3_TEXT);
    $st->execute();

    // Install (idempotent) parent-side ancestor capture triggers, so a
    // future parent-side UPDATE/DELETE preserves the fork-time row value
    // for this descendant branch BEFORE the parent overwrites it.
    cow_install_parent_triggers($db, $parent_table);

    // Snapshot the fork-time schema (DDL + indexes) for schema-merge.
    // Schema strings are small — this is O(num_tables × DDL size) and
    // doesn't compromise the row-storage savings.
    //
    // Use the LOGICAL name (the view's name) for both the DDL's
    // CREATE TABLE clause AND the (branch_id, table_name) key, so
    // merge.php's lookup-by-source-table-name finds it AND the DDL
    // parser can extract column shapes via PRAGMA on a probe table
    // re-attached from this DDL string.
    $raw_ddl = (string)$db->querySingle(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
        . SQLite3::escapeString($ddl_source) . "'"
    );
    $ancestor_ddl = preg_replace(
        '/^(CREATE\s+TABLE\s+)(?:IF\s+NOT\s+EXISTS\s+)?"?'
        . preg_quote($ddl_source, '/') . '"?/is',
        '$1IF NOT EXISTS "' . $logical_name . '"',
        $raw_ddl, 1
    );
    $idxs = [];
    $ix = $db->query(
        "SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='"
        . SQLite3::escapeString($ddl_source) . "' AND sql IS NOT NULL"
    );
    $parent_prefix_for_idx = "b{$parent_id}_wp_";
    $branch_prefix_for_idx = "b{$branch_id}_wp_";
    while ($irow = $ix->fetchArray(SQLITE3_NUM)) {
        // Rewrite the parent-side index DDL to reference the branch's
        // logical names. This keeps schema_normalize_index_ddl producing
        // the SAME normalized key for "ancestor index" and "branch's
        // current index" — so dropping/adding indexes shows up cleanly
        // in the schema diff. Without this rewrite, the ancestor DDL
        // would still embed the parent's prefix, never normalizing to
        // match the branch's prefix-rewritten overlay index.
        $isql = $irow[0];
        // Just str_replace the prefix; the schema-merge normalization will
        // strip it again on both sides. We avoid regex quote/identifier
        // matching here because the DDL may use bare or quoted forms.
        $isql = str_replace($parent_prefix_for_idx, $branch_prefix_for_idx, $isql);
        $idxs[] = $isql;
    }
    $sch = $db->prepare(
        "INSERT OR REPLACE INTO db_snapshots_schema "
      . "(branch_id, table_name, ddl_sql, indexes_json) "
      . "VALUES (:b, :t, :d, :i)"
    );
    $sch->bindValue(':b', $branch_id,    SQLITE3_INTEGER);
    $sch->bindValue(':t', $logical_name, SQLITE3_TEXT);
    $sch->bindValue(':d', $ancestor_ddl ?: $raw_ddl, SQLITE3_TEXT);
    $sch->bindValue(':i', json_encode($idxs, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
    $sch->execute();
}

/** Recreate views (and triggers) for $table_suffix in every branch whose
 *  view depends on it. Used after a schema-altering operation on a parent
 *  table — `SELECT *` in views is resolved at definition time, so a new
 *  column would otherwise be invisible to descendants. */
function cow_recreate_views_for_table(SQLite3 $db, string $table_suffix): void {
    // Find every branch whose db_cow_branches row mentions this suffix.
    $rows = [];
    $st = $db->prepare(
        "SELECT branch_id, parent_branch_id, parent_table_name "
      . "FROM db_cow_branches WHERE table_suffix = :s"
    );
    $st->bindValue(':s', $table_suffix, SQLITE3_TEXT);
    $r = $st->execute();
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;

    foreach ($rows as $row) {
        $bid          = (int)$row['branch_id'];
        $parent_table = (string)$row['parent_table_name'];
        $logical_name = "b{$bid}_wp_{$table_suffix}";
        $overlay_name = $logical_name . '__overlay';
        $tomb_name    = $logical_name . '__tombstones';

        // Skip branches whose objects don't exist (they may have been deleted).
        if (!cow_is_view($db, $logical_name)) continue;

        // Re-derive PK + columns from the parent, since the schema may have
        // grown a column.
        $pk_cols = cow_extract_pk_cols($db, $parent_table);
        $columns = cow_table_columns($db, $parent_table);
        if (empty($columns)) continue;

        // Sync the overlay's column set: ALTER TABLE ADD COLUMN for any
        // column that exists in the parent but not in the overlay.
        $overlay_cols = cow_table_columns($db, $overlay_name);
        $missing = array_diff($columns, $overlay_cols);
        if (!empty($missing)) {
            // Pull the parent's DDL and extract the column-definition
            // fragment for each missing column.
            $parent_ddl = (string)$db->querySingle(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='"
                . SQLite3::escapeString($parent_table) . "'"
            );
            // For each missing column, extract its definition from parent DDL.
            foreach ($missing as $mcol) {
                $def = cow_extract_col_def($parent_ddl, $mcol);
                if ($def === null) continue;
                @$db->exec('ALTER TABLE "' . $overlay_name . '" ADD COLUMN ' . $def);
            }
        }

        // Drop old view + triggers, recreate.
        $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_ins\"");
        $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_upd\"");
        $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_del\"");
        $db->exec("DROP VIEW IF EXISTS \"$logical_name\"");

        $view_sql_body = cow_resolve_view_sql(
            $overlay_name, $tomb_name, $parent_table, $pk_cols, $columns
        );
        $db->exec("CREATE VIEW \"$logical_name\" AS $view_sql_body");
        $defaults = [];
        $pi = $db->query('PRAGMA table_info("' . SQLite3::escapeString($overlay_name) . '")');
        while ($prow = $pi->fetchArray(SQLITE3_ASSOC)) {
            if ($prow['dflt_value'] !== null) {
                $defaults[$prow['name']] = (string)$prow['dflt_value'];
            }
        }
        foreach (cow_trigger_sql($logical_name, $overlay_name, $tomb_name, $pk_cols, $columns, $defaults) as $trg) {
            $db->exec($trg);
        }
    }
}

/** Conservative column-DDL extraction from a CREATE TABLE statement.
 *  Returns the fragment we can hand to ALTER TABLE … ADD COLUMN. */
function cow_extract_col_def(string $ddl, string $col_name): ?string {
    if ($ddl === '') return null;
    if (!preg_match('/^[^(]*\((.*)\)\s*[^)]*$/s', $ddl, $m)) return null;
    $body = $m[1];
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
        if (preg_match('/^"?(' . preg_quote($col_name, '/') . ')"?\s+(.+)$/is', $part, $cm)) {
            $up7 = strtoupper(substr(trim($cm[2]), 0, 7));
            if (in_array(substr($up7, 0, 7), ['PRIMARY', 'FOREIGN'])) continue;
            if (substr($up7, 0, 6) === 'UNIQUE') continue;
            if (substr($up7, 0, 5) === 'CHECK')  continue;
            return '"' . $col_name . '" ' . trim($cm[2]);
        }
    }
    return null;
}

/** Drop the COW trio (view, overlay, tombstone) for one table on a branch. */
function cow_drop_branch_table(SQLite3 $db, int $branch_id, string $table_suffix): void {
    $logical_name = "b{$branch_id}_wp_{$table_suffix}";
    $overlay_name = $logical_name . '__overlay';
    $tomb_name    = $logical_name . '__tombstones';

    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_ins\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_upd\"");
    $db->exec("DROP TRIGGER IF EXISTS \"{$logical_name}__cow_del\"");
    $db->exec("DROP VIEW IF EXISTS \"$logical_name\"");
    $db->exec("DROP TABLE IF EXISTS \"$overlay_name\"");
    $db->exec("DROP TABLE IF EXISTS \"$tomb_name\"");
    // Remove sqlite_sequence rows for the dropped overlay so they don't
    // accumulate. Tolerate a missing sqlite_sequence table.
    @$db->exec("DELETE FROM sqlite_sequence WHERE name='"
             . SQLite3::escapeString($overlay_name) . "'");
}

/** Migrate an existing legacy (full-copy) branch to COW format in-place.
 *  For each b{bid}_wp_X real table on the branch, compute a per-row diff
 *  vs the parent's CURRENT state and:
 *    - Create the COW trio (overlay + tombstones + view).
 *    - Insert into overlay every row whose value differs from the parent's
 *      same-PK row (the branch's "modifications" + "additions").
 *    - Insert into tombstones every PK present in the parent but missing
 *      in the branch (the branch's "deletions").
 *    - Drop the old real table.
 *
 *  Limited to single-PK and composite-PK tables that we can match by PK.
 *  Returns the number of tables migrated. */
function cow_migrate_legacy_branch(SQLite3 $db, int $branch_id): int {
    $parent_id = cow_parent_branch_id($db, $branch_id);
    if ($parent_id <= 0) return 0; // no parent: nothing to COW against

    $prefix = "b{$branch_id}_wp_";
    $tables = [];
    $r = $db->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '"
        . SQLite3::escapeString($prefix) . "%' "
        . "AND name NOT LIKE '%\\_\\_overlay' ESCAPE '\\' "
        . "AND name NOT LIKE '%\\_\\_tombstones' ESCAPE '\\'"
    );
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $name = (string)$row['name'];
        // Skip overlay / tombstone artifact names just in case.
        if (str_ends_with($name, '__overlay') || str_ends_with($name, '__tombstones')) continue;
        $tables[] = $name;
    }

    $migrated = 0;
    foreach ($tables as $branch_table) {
        $suffix = substr($branch_table, strlen($prefix));
        $parent_table = "b{$parent_id}_wp_{$suffix}";
        // If parent doesn't have the corresponding table/view, skip — this
        // is a branch-only table; just leave it alone.
        if (!cow_is_table($db, $parent_table) && !cow_is_view($db, $parent_table)) continue;

        $pk_cols = cow_extract_pk_cols($db, $branch_table);
        $columns = cow_table_columns($db, $branch_table);

        // Snapshot the branch's current rows BEFORE we drop the table.
        $branch_rows = [];
        $rs = $db->query('SELECT * FROM "' . SQLite3::escapeString($branch_table) . '"');
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
            if (empty($pk_cols)) {
                $key = json_encode($row, JSON_UNESCAPED_UNICODE);
            } else {
                $pk_map = [];
                foreach ($pk_cols as $c) $pk_map[$c] = $row[$c] ?? null;
                $key = json_encode($pk_map, JSON_UNESCAPED_UNICODE);
            }
            $branch_rows[$key] = $row;
        }

        // Snapshot parent's current rows for diffing.
        $parent_rows = [];
        $rp = $db->query('SELECT * FROM "' . SQLite3::escapeString($parent_table) . '"');
        while ($row = $rp->fetchArray(SQLITE3_ASSOC)) {
            if (empty($pk_cols)) {
                $key = json_encode($row, JSON_UNESCAPED_UNICODE);
            } else {
                $pk_map = [];
                foreach ($pk_cols as $c) $pk_map[$c] = $row[$c] ?? null;
                $key = json_encode($pk_map, JSON_UNESCAPED_UNICODE);
            }
            $parent_rows[$key] = $row;
        }

        // DROP the real table to make room for the view.
        $db->exec('DROP TABLE "' . SQLite3::escapeString($branch_table) . '"');

        // Create COW trio.
        cow_create_branch_table($db, $branch_id, $parent_id, $suffix);

        $logical_name = $branch_table; // same name; now a view
        $overlay_name = $logical_name . '__overlay';
        $tomb_name    = $logical_name . '__tombstones';

        // Compute the diff. Anything in branch_rows that differs from
        // parent_rows[same key] (or has no parent counterpart) → overlay.
        $col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert_overlay = $db->prepare(
            "INSERT OR REPLACE INTO \"$overlay_name\" ($col_list) VALUES ($placeholders)"
        );
        foreach ($branch_rows as $key => $row) {
            $parent_row = $parent_rows[$key] ?? null;
            if ($parent_row !== null && $parent_row == $row) continue; // identical → no overlay needed
            $i = 1;
            foreach ($columns as $c) {
                $v = $row[$c] ?? null;
                $type = match (true) {
                    $v === null  => SQLITE3_NULL,
                    is_int($v)   => SQLITE3_INTEGER,
                    is_float($v) => SQLITE3_FLOAT,
                    default      => SQLITE3_TEXT,
                };
                $insert_overlay->bindValue($i++, $v, $type);
            }
            $insert_overlay->execute();
            $insert_overlay->reset();
        }

        // Tombstones: parent rows with no branch counterpart.
        if (!empty($pk_cols)) {
            $tomb_col_list = implode(', ', array_map(fn($c) => '"' . $c . '"', $pk_cols));
            $tomb_ph = implode(', ', array_fill(0, count($pk_cols), '?'));
            $insert_tomb = $db->prepare(
                "INSERT OR REPLACE INTO \"$tomb_name\" ($tomb_col_list) VALUES ($tomb_ph)"
            );
            foreach ($parent_rows as $key => $prow) {
                if (isset($branch_rows[$key])) continue;
                $i = 1;
                foreach ($pk_cols as $c) {
                    $v = $prow[$c] ?? null;
                    $type = match (true) {
                        $v === null  => SQLITE3_NULL,
                        is_int($v)   => SQLITE3_INTEGER,
                        is_float($v) => SQLITE3_FLOAT,
                        default      => SQLITE3_TEXT,
                    };
                    $insert_tomb->bindValue($i++, $v, $type);
                }
                $insert_tomb->execute();
                $insert_tomb->reset();
            }
        }
        $migrated++;
    }
    return $migrated;
}
