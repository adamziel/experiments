<?php
/**
 * Subset of branchctl.php helpers adapted for git_server use.
 * These duplicate the functions from branchctl.php to avoid
 * requiring the CLI entry point in a web context.
 */

if (!function_exists('git_fs_resolve_tree')) {

function git_fs_resolve_tree(SQLite3 $db, int $branch_id): array {
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

function git_fs_tree_digest(array $tree): string {
    ksort($tree);
    $h = hash_init('sha256');
    foreach ($tree as $p => $e) {
        hash_update($h, $p . "\0" . ($e['blob_hash'] ?? '') . "\0" . (int)$e['is_dir'] . "\n");
    }
    return hash_final($h);
}

function git_fs_last_commit(SQLite3 $db, int $branch_id): ?array {
    $s = $db->prepare("SELECT id, dolt_hash, message, created_at FROM fs_commits WHERE branch_id = :b ORDER BY id DESC LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function git_fs_find_commit_by_dolt_hash(SQLite3 $db, int $branch_id, string $dolt_hash): ?array {
    $s = $db->prepare("SELECT id, dolt_hash, message, created_at FROM fs_commits WHERE branch_id = :b AND dolt_hash = :h LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $s->bindValue(':h', $dolt_hash, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function git_fs_digest_of_commit(SQLite3 $db, int $commit_id): string {
    $tree = [];
    $r = $db->query("SELECT path, blob_hash, is_dir FROM fs_commit_files WHERE commit_id = $commit_id");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $tree[$row['path']] = $row;
    }
    return git_fs_tree_digest($tree);
}

function git_fs_record_snapshot(SQLite3 $db, int $branch_id, string $dolt_hash, string $message): int {
    $tree = git_fs_resolve_tree($db, $branch_id);
    $parent = git_fs_last_commit($db, $branch_id);
    $parent_id = $parent['id'] ?? null;

    $db->exec('BEGIN IMMEDIATE');
    try {
        $s = $db->prepare("INSERT INTO fs_commits (branch_id, dolt_hash, parent_id, message) VALUES (:b, :h, :p, :m)");
        $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
        $s->bindValue(':h', $dolt_hash, SQLITE3_TEXT);
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
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function git_fs_branch_id(SQLite3 $db, string $name): int {
    $s = $db->prepare("SELECT id FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0);
}

function git_dolt_head_hash(mysqli $c, string $branch): string {
    $esc = $c->real_escape_string($branch);
    $r = $c->query("SELECT hash FROM dolt_branches WHERE name = '$esc' LIMIT 1");
    if (!($r instanceof mysqli_result)) return '';
    $row = $r->fetch_assoc();
    $r->free();
    return $row['hash'] ?? '';
}

function git_fs_migrate(SQLite3 $db): void {
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS fs_commits (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    branch_id   INTEGER NOT NULL,
    dolt_hash   TEXT NOT NULL,
    parent_id   INTEGER,
    message     TEXT,
    created_at  TEXT DEFAULT (datetime('now')),
    UNIQUE (branch_id, dolt_hash),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (parent_id) REFERENCES fs_commits(id)
);
CREATE INDEX IF NOT EXISTS idx_fs_commits_branch ON fs_commits(branch_id);
CREATE INDEX IF NOT EXISTS idx_fs_commits_dolt ON fs_commits(branch_id, dolt_hash);
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
SQL);
}

} // end function_exists guard
