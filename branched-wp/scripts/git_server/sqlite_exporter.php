<?php
/**
 * Dolt → SQLite exporter for the git clone/fetch side.
 *
 * Produces a single `.ht.sqlite` file that boots under the WordPress
 * SQLite Database Integration plugin, so the cloned tree is a runnable
 * WordPress install.
 *
 * Approach:
 *   1. Translate each wp_* CREATE TABLE from Dolt (MySQL dialect) into
 *      SQLite by feeding it through WP_SQLite_Driver — the same translator
 *      the runtime plugin uses, which guarantees plugin↔file compatibility.
 *   2. Bulk-insert rows with raw SQLite3 prepared statements. This skips
 *      the MySQL-on-SQLite parse path for each INSERT (orders of magnitude
 *      faster) while producing bytes the plugin can still read at runtime.
 *
 * Skips wp_options transients so ephemeral WP state doesn't churn every
 * clone (same filter as the old NDJSON path).
 */

if (!class_exists('WP_SQLite_Driver', false)) {
    if (!defined('ABSPATH')) define('ABSPATH', '/tmp/branchfs-sqlite-exporter/');
    if (!defined('WP_CLI'))  define('WP_CLI', true);
    require_once __DIR__ . '/../../vendor/sqlite-database-integration/wp-includes/database/load.php';
}

/**
 * Export every row of every WP table on $branch from Dolt into a fresh
 * SQLite file at $sqlite_path. Overwrites any pre-existing file.
 *
 * Returns an associative array {table => row_count}.
 */
function sqlite_exporter_build_file(mysqli $dolt, string $branch, string $sqlite_path, array $tables): array {
    // Fresh file — the translator populates driver metadata on first open.
    @unlink($sqlite_path);
    $dir = dirname($sqlite_path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $esc = $dolt->real_escape_string($branch);
    $dolt->query("CALL DOLT_CHECKOUT('$esc')");
    sqlite_exporter_drain($dolt);

    // Phase 1: DDL via the plugin's own translator.
    $pdo  = new PDO('sqlite:' . $sqlite_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn = new WP_SQLite_Connection(['pdo' => $pdo]);
    $driver = new WP_SQLite_Driver($conn, 'wordpress');

    $driver->query('BEGIN');
    foreach ($tables as $t) {
        $r = $dolt->query("SHOW CREATE TABLE `$t`");
        if (!($r instanceof mysqli_result)) {
            sqlite_exporter_drain($dolt);
            throw new \RuntimeException("SHOW CREATE TABLE `$t` failed on branch '$branch'");
        }
        $row = $r->fetch_assoc();
        $r->free();
        sqlite_exporter_drain($dolt);

        $ddl = $row['Create Table'] ?? '';
        if ($ddl === '') {
            throw new \RuntimeException("empty DDL for `$t`");
        }

        try {
            $driver->query($ddl);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "DDL translation failed for `$t`: " . $e->getMessage() .
                "\n--- original DDL ---\n" . $ddl
            );
        }
    }
    $driver->query('COMMIT');

    // Release the driver before we switch to raw SQLite3 for inserts — they
    // contend on the same file lock otherwise.
    unset($driver, $conn, $pdo);
    gc_collect_cycles();

    // Phase 2: bulk-insert rows.
    $sqlite = new SQLite3($sqlite_path);
    $sqlite->busyTimeout(10000);
    $sqlite->exec('PRAGMA journal_mode = MEMORY');
    $sqlite->exec('PRAGMA synchronous = OFF');
    $sqlite->exec('BEGIN');

    $counts = [];
    try {
        foreach ($tables as $t) {
            $counts[$t] = sqlite_exporter_copy_table($dolt, $sqlite, $t);
        }
        $sqlite->exec('COMMIT');
    } catch (\Throwable $e) {
        $sqlite->exec('ROLLBACK');
        $sqlite->close();
        throw $e;
    }
    $sqlite->exec('VACUUM');
    $sqlite->close();

    return $counts;
}

/**
 * Read all rows of one table from the active Dolt connection, INSERT them
 * into the SQLite file. Uses binary-safe prepared statements so BLOB
 * columns round-trip without corruption.
 *
 * Returns the number of rows inserted.
 */
function sqlite_exporter_copy_table(mysqli $dolt, SQLite3 $sqlite, string $table): int {
    $where = '';
    if ($table === 'wp_options') {
        // Transients are WP-managed ephemeral state — omit from the git
        // export to avoid churning the repo on every clone.
        // Dolt doesn't accept LIKE ... ESCAPE '\\' the way MySQL does,
        // so we filter with SUBSTRING() + literal prefix match.
        $where = " WHERE SUBSTRING(`option_name`, 1, 11) <> '_transient_'"
               .  " AND SUBSTRING(`option_name`, 1, 16) <> '_site_transient_'"
               .  " AND SUBSTRING(`option_name`, 1, 19) <> '_transient_timeout_'";
    }

    // Discover the SQLite column order so we can align the SELECT.
    $cols = sqlite_exporter_sqlite_columns($sqlite, $table);
    if (empty($cols)) {
        return 0;
    }

    // SELECT in the SQLite column order so bind positions line up.
    $col_list = implode(',', array_map(fn($c) => "`$c`", $cols));
    $r = $dolt->query("SELECT $col_list FROM `$table`$where");
    if (!($r instanceof mysqli_result)) {
        sqlite_exporter_drain($dolt);
        return 0;
    }
    $fields = $r->fetch_fields();

    // Build prepared INSERT.
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $quoted_cols  = implode(',', array_map(fn($c) => '`' . str_replace('`','``',$c) . '`', $cols));
    $stmt = $sqlite->prepare("INSERT INTO `$table` ($quoted_cols) VALUES ($placeholders)");
    if ($stmt === false) {
        $r->free();
        throw new \RuntimeException("cannot prepare INSERT for `$table`");
    }

    $count = 0;
    while ($row = $r->fetch_row()) {
        $stmt->reset();
        $stmt->clear();
        foreach ($row as $i => $val) {
            $type = sqlite_exporter_bind_type($fields[$i], $val);
            $stmt->bindValue($i + 1, $val, $type);
        }
        $res = $stmt->execute();
        if ($res === false) {
            $err = $sqlite->lastErrorMsg();
            $r->free();
            throw new \RuntimeException("INSERT into `$table` failed: $err");
        }
        $res->finalize();
        $count++;
    }
    $stmt->close();
    $r->free();
    sqlite_exporter_drain($dolt);
    return $count;
}

/**
 * Columns of a SQLite table in declaration order.
 */
function sqlite_exporter_sqlite_columns(SQLite3 $sqlite, string $table): array {
    $cols = [];
    $r = $sqlite->query("PRAGMA table_info(`" . str_replace('`','``',$table) . "`)");
    if (!$r) return [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $cols[] = $row['name'];
    }
    return $cols;
}

/**
 * Pick an SQLite3 bind type for a MySQL column value. BLOB columns that
 * hold non-UTF-8 bytes go in as SQLITE3_BLOB so the bytes survive the
 * round-trip; textual BLOBs stay TEXT.
 */
function sqlite_exporter_bind_type(object $field, $val): int {
    if ($val === null) return SQLITE3_NULL;
    $blob_types = [MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB];
    if (in_array((int)$field->type, $blob_types, true)) {
        // In the mysqli binary-string flag (bit 128) = true means it's a real BLOB.
        // For long text columns mysqli still reports TYPE_BLOB but with the
        // binary flag cleared — detect by sniffing for invalid UTF-8.
        if (is_string($val) && $val !== '' && !sqlite_exporter_is_utf8($val)) {
            return SQLITE3_BLOB;
        }
        return SQLITE3_TEXT;
    }
    return SQLITE3_TEXT;
}

function sqlite_exporter_is_utf8(string $s): bool {
    if (function_exists('mb_check_encoding')) {
        return mb_check_encoding($s, 'UTF-8');
    }
    return preg_match('//u', $s) === 1;
}

function sqlite_exporter_drain(mysqli $c): void {
    while ($c->next_result()) {
        $r = $c->store_result();
        if ($r instanceof mysqli_result) $r->free();
    }
}
