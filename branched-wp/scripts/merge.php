<?php
/**
 * BranchFS Merge — coordinated database (Dolt) + files (SQLite) merge.
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
 * Usage: php merge.php <source-branch> <target-branch> [db-path] [dolt-db-name] [--strategy=...]
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php merge.php <source-branch> <target-branch> [db-path] [dolt-db-name] [--strategy=abort|ours|theirs]\n");
    exit(1);
}

$source  = $argv[1];
$target  = $argv[2];
$db_path = $argv[3] ?? __DIR__ . '/../branchfs.db';
$dolt_db = null;
$strategy = 'abort';

for ($i = 3; $i < $argc; $i++) {
    $a = $argv[$i];
    if (strpos($a, '--strategy=') === 0) {
        $strategy = substr($a, strlen('--strategy='));
    } elseif ($a === '--strategy' && isset($argv[$i + 1])) {
        $strategy = $argv[++$i];
    } elseif ($a[0] !== '-' && $dolt_db === null && $i > 3) {
        // Legacy 4th positional: dolt db name.
        $dolt_db = $a;
    }
}
// Positional fallback for dolt_db (backwards compatible with previous callers)
if ($dolt_db === null && isset($argv[4]) && $argv[4][0] !== '-' && strpos($argv[4], '--') !== 0) {
    $dolt_db = $argv[4];
}

if (!in_array($strategy, ['abort', 'ours', 'theirs'], true)) {
    fwrite(STDERR, "merge: invalid --strategy '$strategy' (must be abort|ours|theirs)\n");
    exit(1);
}

if (!extension_loaded('branchfs')) {
    fwrite(STDERR, "ERROR: branchfs extension not loaded\n");
    exit(1);
}

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
        $applied++;
    }
    $db->exec('COMMIT');
    echo "  applied: $applied rows\n";
} catch (\Throwable $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, "merge: file-side failed: " . $e->getMessage() . "\n");
    exit(4);
}
$db->close();

// --- Phase 2: Dolt database merge ---
if ($dolt_db) {
    echo "\nPhase 2: Dolt database merge ('$dolt_db') ...\n";

    $host = getenv('DOLT_HOST') ?: '127.0.0.1';
    $port = (int)(getenv('DOLT_PORT') ?: '13306');
    $user = getenv('DOLT_USER') ?: 'root';
    $pass = getenv('DOLT_PASSWORD') ?: '';

    try {
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
    } catch (\Throwable $e) {
        echo "  Dolt merge error: " . $e->getMessage() . "\n";
        echo "  (Dolt integration requires a running Dolt SQL server)\n";
    }
} else {
    echo "\nPhase 2: Skipped (no Dolt database specified)\n";
}

echo "\nMerge complete.\n";
