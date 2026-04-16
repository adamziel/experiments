<?php
/**
 * SQLite → Dolt importer for the git push side.
 *
 * Reads the pushed `.ht.sqlite` file (via PHP's native SQLite3 — never
 * shells out to the sqlite3 CLI) and reconciles its row set against the
 * current state of a target Dolt branch. Emits INSERT / UPDATE / DELETE
 * against Dolt via mysqli.
 *
 * Schema migrations: if the SQLite file has columns Dolt doesn't (or
 * vice-versa), emit ALTER TABLE on Dolt first. Anything ambiguous
 * (e.g. renamed columns, type-narrowing) is rejected — the admin must
 * go through a real migration tool for those.
 */

if (!function_exists('sqlite_importer_apply')) {

function sqlite_importer_apply(mysqli $dolt, string $dolt_db, string $branch, string $sqlite_path, array $tables, array $table_pk): void {
    if (!is_file($sqlite_path)) {
        throw new \RuntimeException("pushed .ht.sqlite not found at '$sqlite_path' — cannot apply DB changes");
    }
    $sqlite = new SQLite3($sqlite_path, SQLITE3_OPEN_READONLY);
    $sqlite->busyTimeout(10000);

    try {
        $esc = $dolt->real_escape_string($branch);
        $dolt->query("CALL DOLT_CHECKOUT('$esc')");
        sqlite_importer_drain($dolt);

        foreach ($tables as $table) {
            $pk = $table_pk[$table] ?? null;
            if (!$pk) continue;
            if (!sqlite_importer_table_exists($sqlite, $table)) {
                // The pushed file is missing a table we expected. Treat that
                // as a destructive change and reject — the user probably
                // truncated the DB accidentally.
                throw new \RuntimeException(
                    "pushed .ht.sqlite is missing required table `$table`"
                );
            }

            sqlite_importer_reconcile_schema($dolt, $sqlite, $table);
            sqlite_importer_reconcile_rows($dolt, $sqlite, $table, $pk);
        }
    } finally {
        $sqlite->close();
    }
}

/**
 * Compare column names between the SQLite copy and Dolt. Emit ALTER
 * TABLE ADD / DROP COLUMN as needed. Reject ambiguous changes.
 */
function sqlite_importer_reconcile_schema(mysqli $dolt, SQLite3 $sqlite, string $table): void {
    $sq_cols = sqlite_importer_sqlite_columns($sqlite, $table);
    $my_cols = sqlite_importer_mysql_columns($dolt, $table);

    $sq_names = array_map('strtolower', array_keys($sq_cols));
    $my_names = array_map('strtolower', array_keys($my_cols));

    $added   = array_values(array_diff($sq_names, $my_names));
    $removed = array_values(array_diff($my_names, $sq_names));

    if (!$added && !$removed) return;

    // If both sides diverge simultaneously, we can't tell renames from
    // adds+drops. Refuse to guess.
    if ($added && $removed) {
        throw new \RuntimeException(
            "refusing to apply ambiguous schema change on `$table`: " .
            "SQLite added [" . implode(',', $added) . "] and removed [" . implode(',', $removed) . "]. " .
            "Run an explicit ALTER TABLE on the server instead."
        );
    }

    foreach ($added as $lc) {
        $orig = null;
        foreach ($sq_cols as $name => $_) {
            if (strtolower($name) === $lc) { $orig = $name; break; }
        }
        if ($orig === null) continue;
        $esc_table = $dolt->real_escape_string($table);
        $esc_col   = $dolt->real_escape_string($orig);
        // SQLite types are loose; TEXT NULL is the widest compatible choice.
        $dolt->query("ALTER TABLE `$esc_table` ADD COLUMN `$esc_col` TEXT NULL");
        sqlite_importer_drain($dolt);
    }

    foreach ($removed as $lc) {
        $orig = null;
        foreach ($my_cols as $name => $_) {
            if (strtolower($name) === $lc) { $orig = $name; break; }
        }
        if ($orig === null) continue;
        $esc_table = $dolt->real_escape_string($table);
        $esc_col   = $dolt->real_escape_string($orig);
        $dolt->query("ALTER TABLE `$esc_table` DROP COLUMN `$esc_col`");
        sqlite_importer_drain($dolt);
    }
}

/**
 * Build a diff of rows and apply INSERT/UPDATE/DELETE to Dolt.
 *
 * Strategy:
 *   - Pull SQLite rows (authoritative) keyed by PK.
 *   - Pull Dolt rows keyed by PK.
 *   - For each PK in SQLite: INSERT (new) or UPDATE (changed).
 *   - For each PK in Dolt not in SQLite: DELETE — EXCEPT wp_options
 *     transients, which are stripped from git export but live in Dolt.
 */
function sqlite_importer_reconcile_rows(mysqli $dolt, SQLite3 $sqlite, string $table, string $pk): void {
    $pk_cols = array_map('trim', explode(',', $pk));

    $sq_cols = sqlite_importer_sqlite_columns($sqlite, $table);
    $my_cols = sqlite_importer_mysql_columns($dolt, $table);

    $col_names = array_keys($my_cols);

    // --- Fetch SQLite rows. For wp_options we filter transients out
    // of the "to apply" set — once the clone booted locally WordPress
    // will have written its own transient entries into the SQLite
    // file, and pushing those to Dolt is both unnecessary churn AND
    // risks colliding with existing server-side transients under a
    // different option_id. But we keep the set of PKs that exist in
    // SQLite (even as transients) so the delete phase can tell
    // "PK absent" from "PK present but transient". ---
    $col_list = implode(',', array_map(fn($c) => '`' . str_replace('`','``',$c) . '`', $col_names));
    $sql = "SELECT $col_list FROM `" . str_replace('`','``',$table) . "`";
    $r = $sqlite->query($sql);
    $sq_rows = [];
    $sq_all_keys = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $key = sqlite_importer_pk_key($row, $pk_cols);
        $sq_all_keys[$key] = true;
        if ($table === 'wp_options' && sqlite_importer_is_transient_row($row)) continue;
        $sq_rows[$key] = $row;
    }

    // --- Fetch ALL Dolt rows (no filter). We need them unfiltered to
    // spot PK collisions with rows that were excluded from the exported
    // SQLite (transients in wp_options) but still live on the server.
    // Without this, a row that WP later rewrote as a transient under
    // the same PK would trip a "duplicate primary key" INSERT. ---
    $esc_table = $dolt->real_escape_string($table);
    $dolt_sql = "SELECT $col_list FROM `$esc_table`";
    $dr = $dolt->query($dolt_sql);
    $dolt_rows = [];
    if ($dr instanceof mysqli_result) {
        while ($row = $dr->fetch_assoc()) {
            $key = sqlite_importer_pk_key($row, $pk_cols);
            $dolt_rows[$key] = $row;
        }
        $dr->free();
    }
    sqlite_importer_drain($dolt);

    // --- Apply diff ---
    // Inserts + updates
    foreach ($sq_rows as $key => $sq_row) {
        if (!isset($dolt_rows[$key])) {
            sqlite_importer_insert($dolt, $table, $sq_row);
        } elseif (!sqlite_importer_rows_equal($sq_row, $dolt_rows[$key])) {
            sqlite_importer_update($dolt, $table, $sq_row, $pk_cols);
        }
    }
    // Deletes — for composite PK, stick with the old truncate-and-reinsert
    // behavior: deleting by composite key from PHP is fiddly and the pool
    // is small (wp_term_relationships).
    if (count($pk_cols) === 1) {
        foreach ($dolt_rows as $key => $row) {
            if (isset($sq_rows[$key])) continue;
            // Preserve live transients in wp_options — they're WP-managed
            // ephemeral state, never in the exported SQLite, and deleting
            // them would churn the site on every push.
            if ($table === 'wp_options' && sqlite_importer_is_transient_row($row)) continue;
            // Ignore deletes whose PK still exists in SQLite as a
            // transient — the clone converted a non-transient into a
            // transient post-boot, which we don't try to propagate.
            if ($table === 'wp_options' && isset($sq_all_keys[$key])) continue;
            sqlite_importer_delete($dolt, $table, $pk_cols[0], $row[$pk_cols[0]]);
        }
    } else {
        foreach ($dolt_rows as $key => $row) {
            if (isset($sq_rows[$key])) continue;
            sqlite_importer_delete_composite($dolt, $table, $pk_cols, $row);
        }
    }
}

function sqlite_importer_is_transient_row(array $row): bool {
    $name = $row['option_name'] ?? '';
    return str_starts_with($name, '_transient_')
        || str_starts_with($name, '_site_transient_')
        || str_starts_with($name, '_transient_timeout_');
}

function sqlite_importer_insert(mysqli $dolt, string $table, array $row): void {
    $esc_table = $dolt->real_escape_string($table);
    $cols = [];
    $vals = [];
    foreach ($row as $col => $val) {
        $cols[] = '`' . $dolt->real_escape_string($col) . '`';
        $vals[] = sqlite_importer_quote($dolt, $val);
    }
    $sql = "INSERT INTO `$esc_table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    if (!@$dolt->query($sql)) {
        throw new \RuntimeException("INSERT into `$table` failed: " . $dolt->error);
    }
    sqlite_importer_drain($dolt);
}

function sqlite_importer_update(mysqli $dolt, string $table, array $row, array $pk_cols): void {
    $esc_table = $dolt->real_escape_string($table);
    $sets = [];
    foreach ($row as $col => $val) {
        if (in_array($col, $pk_cols, true)) continue;
        $sets[] = '`' . $dolt->real_escape_string($col) . '` = ' . sqlite_importer_quote($dolt, $val);
    }
    if (!$sets) return;
    $where = [];
    foreach ($pk_cols as $pk) {
        $where[] = '`' . $dolt->real_escape_string($pk) . '` = ' . sqlite_importer_quote($dolt, $row[$pk]);
    }
    $sql = "UPDATE `$esc_table` SET " . implode(',', $sets) . " WHERE " . implode(' AND ', $where);
    if (!@$dolt->query($sql)) {
        throw new \RuntimeException("UPDATE on `$table` failed: " . $dolt->error);
    }
    sqlite_importer_drain($dolt);
}

function sqlite_importer_delete(mysqli $dolt, string $table, string $pk_col, $pk_val): void {
    $esc_table = $dolt->real_escape_string($table);
    $esc_pk    = $dolt->real_escape_string($pk_col);
    $q         = sqlite_importer_quote($dolt, $pk_val);
    if (!@$dolt->query("DELETE FROM `$esc_table` WHERE `$esc_pk` = $q")) {
        throw new \RuntimeException("DELETE from `$table` failed: " . $dolt->error);
    }
    sqlite_importer_drain($dolt);
}

function sqlite_importer_delete_composite(mysqli $dolt, string $table, array $pk_cols, array $row): void {
    $esc_table = $dolt->real_escape_string($table);
    $where = [];
    foreach ($pk_cols as $pk) {
        $where[] = '`' . $dolt->real_escape_string($pk) . '` = ' . sqlite_importer_quote($dolt, $row[$pk]);
    }
    $sql = "DELETE FROM `$esc_table` WHERE " . implode(' AND ', $where);
    if (!@$dolt->query($sql)) {
        throw new \RuntimeException("DELETE from `$table` failed: " . $dolt->error);
    }
    sqlite_importer_drain($dolt);
}

function sqlite_importer_quote(mysqli $dolt, $v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return "'" . $dolt->real_escape_string((string)$v) . "'";
}

/**
 * Composite-safe PK serialization used as an assoc-array key.
 */
function sqlite_importer_pk_key(array $row, array $pk_cols): string {
    $parts = [];
    foreach ($pk_cols as $c) {
        $parts[] = (string)($row[$c] ?? '');
    }
    return implode("\x1F", $parts);
}

/**
 * String-wise row equality. Both sides come out of the mysqli / sqlite
 * drivers as strings, so this is the correct comparison.
 */
function sqlite_importer_rows_equal(array $a, array $b): bool {
    if (count($a) !== count($b)) return false;
    foreach ($a as $k => $v) {
        if (!array_key_exists($k, $b)) return false;
        $bv = $b[$k];
        if ($v === null && $bv === null) continue;
        if ($v === null || $bv === null) return false;
        if ((string)$v !== (string)$bv) return false;
    }
    return true;
}

function sqlite_importer_sqlite_columns(SQLite3 $sqlite, string $table): array {
    $cols = [];
    $r = $sqlite->query("PRAGMA table_info(`" . str_replace('`','``',$table) . "`)");
    if (!$r) return [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[$row['name']] = $row;
    }
    return $cols;
}

function sqlite_importer_mysql_columns(mysqli $dolt, string $table): array {
    $cols = [];
    $esc = $dolt->real_escape_string($table);
    $r = $dolt->query("SHOW COLUMNS FROM `$esc`");
    if (!($r instanceof mysqli_result)) {
        sqlite_importer_drain($dolt);
        return [];
    }
    while ($row = $r->fetch_assoc()) {
        $cols[$row['Field']] = $row;
    }
    $r->free();
    sqlite_importer_drain($dolt);
    return $cols;
}

function sqlite_importer_table_exists(SQLite3 $sqlite, string $table): bool {
    $stmt = $sqlite->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n");
    $stmt->bindValue(':n', $table, SQLITE3_TEXT);
    $r = $stmt->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    $stmt->close();
    return (bool)$row;
}

function sqlite_importer_drain(mysqli $c): void {
    while ($c->next_result()) {
        $r = $c->store_result();
        if ($r instanceof mysqli_result) $r->free();
    }
}

} // function_exists guard
