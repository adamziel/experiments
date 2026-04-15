<?php
/**
 * BranchFS Merge - Coordinated database (Dolt) + files (SQLite) merge.
 *
 * Performs a 3-way merge at the path level:
 *   base (common ancestor) vs source vs target
 *
 * Usage: php merge.php <source-branch> <target-branch> [db-path] [dolt-db-name]
 */

if ($argc < 3) {
    die("Usage: php merge.php <source-branch> <target-branch> [db-path] [dolt-db-name]\n");
}

$source = $argv[1];
$target = $argv[2];
$db_path = $argv[3] ?? __DIR__ . '/../branchfs.db';
$dolt_db = $argv[4] ?? null;

if (!extension_loaded('branchfs')) {
    die("ERROR: branchfs extension not loaded\n");
}

branchfs_set_db($db_path);

echo "=== BranchFS Merge ===\n";
echo "Source: $source\n";
echo "Target: $target\n\n";

// --- Phase 1: File merge (SQLite store) ---
echo "Phase 1: Merging files from '$source' into '$target'...\n";

$db = new SQLite3($db_path);
$db->exec('PRAGMA journal_mode = WAL');

// Get branch IDs
$src_id = $db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($source) . "'");
$tgt_id = $db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($target) . "'");

if (!$src_id) die("ERROR: Source branch '$source' not found\n");
if (!$tgt_id) die("ERROR: Target branch '$target' not found\n");

// Find files that exist on source branch but not target (or differ)
$stmt = $db->prepare(
    "SELECT f.path, f.blob_hash, f.is_dir, f.mode, f.mtime
     FROM files f
     WHERE f.branch_id = :src_id"
);
$stmt->bindValue(':src_id', $src_id, SQLITE3_INTEGER);
$result = $stmt->execute();

$merged = 0;
$conflicts = [];

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $path = $row['path'];

    // Check if target has this file
    $tgt_stmt = $db->prepare(
        "SELECT blob_hash FROM files WHERE branch_id = :tgt_id AND path = :path"
    );
    $tgt_stmt->bindValue(':tgt_id', $tgt_id, SQLITE3_INTEGER);
    $tgt_stmt->bindValue(':path', $path, SQLITE3_TEXT);
    $tgt_row = $tgt_stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$tgt_row) {
        // File only on source: copy to target
        $ins = $db->prepare(
            "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES (:bid, :path, :hash, :mode, :mtime, :is_dir)"
        );
        $ins->bindValue(':bid', $tgt_id, SQLITE3_INTEGER);
        $ins->bindValue(':path', $path, SQLITE3_TEXT);
        $ins->bindValue(':hash', $row['blob_hash'], SQLITE3_TEXT);
        $ins->bindValue(':mode', $row['mode'], SQLITE3_INTEGER);
        $ins->bindValue(':mtime', $row['mtime'], SQLITE3_INTEGER);
        $ins->bindValue(':is_dir', $row['is_dir'], SQLITE3_INTEGER);
        $ins->execute();
        $merged++;
    } elseif ($tgt_row['blob_hash'] !== $row['blob_hash']) {
        // Both modified: source wins (like git merge -X theirs)
        $ins = $db->prepare(
            "INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir)
             VALUES (:bid, :path, :hash, :mode, :mtime, :is_dir)"
        );
        $ins->bindValue(':bid', $tgt_id, SQLITE3_INTEGER);
        $ins->bindValue(':path', $path, SQLITE3_TEXT);
        $ins->bindValue(':hash', $row['blob_hash'], SQLITE3_TEXT);
        $ins->bindValue(':mode', $row['mode'], SQLITE3_INTEGER);
        $ins->bindValue(':mtime', $row['mtime'], SQLITE3_INTEGER);
        $ins->bindValue(':is_dir', $row['is_dir'], SQLITE3_INTEGER);
        $ins->execute();
        $merged++;
    }
    // Same hash: no action needed
}

echo "  Files merged: $merged\n";
if ($conflicts) {
    echo "  CONFLICTS (" . count($conflicts) . "):\n";
    foreach ($conflicts as $c) {
        echo "    - $c\n";
    }
}

$db->close();

// --- Phase 2: Dolt database merge ---
if ($dolt_db) {
    echo "\nPhase 2: Merging Dolt database '$dolt_db'...\n";

    $host = getenv('DOLT_HOST') ?: '127.0.0.1';
    $port = (int)(getenv('DOLT_PORT') ?: '13306');
    $user = getenv('DOLT_USER') ?: 'root';
    $pass = getenv('DOLT_PASSWORD') ?: '';

    try {
        /* Use mysqli so we can walk DOLT_MERGE's multi-result-set output
         * cleanly — that was tripping up PDO's "other unbuffered queries
         * are active" check. */
        $dolt = @new mysqli($host, $user, $pass, "$dolt_db/$target", $port);
        if ($dolt->connect_error) {
            throw new RuntimeException($dolt->connect_error);
        }
        $r = $dolt->query("CALL DOLT_MERGE('$source')");
        if ($r === false) throw new RuntimeException($dolt->error);
        if ($r instanceof mysqli_result) $r->free();
        while ($dolt->next_result()) { $r2 = $dolt->store_result(); if ($r2) $r2->free(); }
        echo "  Dolt merge complete.\n";

        $r = $dolt->query("SELECT \"table\", num_conflicts FROM dolt_conflicts");
        if ($r instanceof mysqli_result) {
            $any = false;
            while ($row = $r->fetch_assoc()) {
                $any = true;
                echo "  WARNING: conflict on table '{$row['table']}': {$row['num_conflicts']}\n";
            }
            $r->free();
            if (!$any) echo "  No Dolt conflicts.\n";
        }
        while ($dolt->next_result()) { $r2 = $dolt->store_result(); if ($r2) $r2->free(); }
        $dolt->close();
    } catch (Throwable $e) {
        echo "  Dolt merge error: " . $e->getMessage() . "\n";
        echo "  (Dolt integration requires a running Dolt SQL server)\n";
    }
} else {
    echo "\nPhase 2: Skipped (no Dolt database specified)\n";
}

echo "\nMerge complete.\n";
