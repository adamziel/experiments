<?php
/**
 * branchctl — manage branchfs + Dolt branches from the command line.
 *
 * Usage:
 *   branchctl list
 *   branchctl create <name> [--from <parent>]
 *   branchctl commit <name> [-m "message"]
 *   branchctl delete <name>
 *   branchctl show <name>
 *
 * Environment (all optional; defaults match e2e/dev.sh defaults):
 *   BRANCHFS_DB      path to the branchfs SQLite file    (/tmp/branchfs-dev/branchfs.db)
 *   DOLT_HOST        Dolt MySQL host                      (127.0.0.1)
 *   DOLT_PORT        Dolt MySQL port                      (13306)
 *   DOLT_USER        Dolt MySQL user                      (root)
 *   DOLT_PASSWORD    Dolt MySQL password                  (empty)
 *   DOLT_DB          Dolt database name                   (wordpress)
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

$DB_PATH  = env_or('BRANCHFS_DB', '/tmp/branchfs-dev/branchfs.db');
$HOST     = env_or('DOLT_HOST', '127.0.0.1');
$PORT     = (int)env_or('DOLT_PORT', '13306');
$USER     = env_or('DOLT_USER', 'root');
$PASS     = env_or('DOLT_PASSWORD', '');
$DOLTDB   = env_or('DOLT_DB', 'wordpress');

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
  branchctl reset   <name>  <commit-hash-or-ref>  [--force]
  branchctl rollback <name>  [--force]

Flags:
  --db <path>          override BRANCHFS_DB (default: /tmp/branchfs-dev/branchfs.db)
  --dolt-host <host>   override DOLT_HOST (default: 127.0.0.1)
  --dolt-port <port>   override DOLT_PORT (default: 13306)

Workflow:
  create   forks BOTH the branchfs overlay and the Dolt branch, and
             records an initial paired fs_commit so reset has a landing.
  commit   commits any pending DB writes on <name> AND snapshots the
             branch's file tree into fs_commits, paired with the new
             Dolt hash.
  log      shows <name>'s Dolt commit history; the FS column marks
             commits that have a paired branchfs snapshot ('*'). Only
             those commits can be `reset` to with files restored.
  diff     per-table row add/del/mod + overlay file counts.
  merge    runs scripts/merge.php: 3-way file merge + DOLT_MERGE,
             result lands on <target>.
  reset    hard-resets <name> to <commit> on Dolt AND restores the
             file overlay from the paired fs_commit if one exists.
             Refuses if the branch has uncommitted file-side changes
             unless --force is given.
  rollback shortcut for `reset <name> HEAD~1`.
  delete   drops the overlay and the Dolt branch. Main is protected.

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

if (isset($flags['db']))        $DB_PATH = (string)$flags['db'];
if (isset($flags['dolt-host'])) $HOST    = (string)$flags['dolt-host'];
if (isset($flags['dolt-port'])) $PORT    = (int)$flags['dolt-port'];

if (!file_exists($DB_PATH)) {
    fwrite(STDERR, "branchctl: branchfs DB not found: $DB_PATH\n");
    fwrite(STDERR, "           Run `bash e2e/dev.sh` first, or set BRANCHFS_DB.\n");
    exit(2);
}

function connect_dolt(string $host, int $port, string $user, string $pass, string $db): mysqli {
    $c = @new mysqli($host, $user, $pass, $db, $port);
    if ($c->connect_error) {
        fwrite(STDERR, "branchctl: cannot connect to Dolt at $host:$port as $user: {$c->connect_error}\n");
        fwrite(STDERR, "           Is `dolt sql-server` running? (e2e/dev.sh starts one.)\n");
        exit(3);
    }
    return $c;
}

function drain(mysqli $c): void {
    while ($c->next_result()) {
        $r = $c->store_result();
        if ($r instanceof mysqli_result) $r->free();
    }
}

function dolt_query(mysqli $c, string $sql): mysqli_result|bool {
    $r = $c->query($sql);
    if ($r === false) {
        fwrite(STDERR, "branchctl: Dolt SQL failed: " . $c->error . "\n  query: $sql\n");
        exit(4);
    }
    return $r;
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

/* ================================================================
 * File-side commit graph (fs_commits + fs_commit_files)
 *
 * Each branchctl commit records a full snapshot of the branch's resolved
 * file tree paired with a Dolt commit hash. reset/rollback look up the
 * matching fs_commit and materialize it back into the branch overlay.
 * Blobs are content-addressed, so the only duplication per commit is the
 * row-per-path in fs_commit_files (small).
 * ================================================================ */

function fs_migrate(SQLite3 $db): void {
    /* Idempotent; runs on every open so existing stores get the new tables
     * without a separate migration step. */
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

function fs_branch_id(SQLite3 $db, string $name): int {
    $s = $db->prepare("SELECT id FROM branches WHERE name = :n");
    $s->bindValue(':n', $name, SQLITE3_TEXT);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return (int)($row[0] ?? 0);
}

/* Walk the branch chain and return the resolved effective tree.
 * Returns [path => ['blob_hash','mode','mtime','is_dir']]. Tombstones
 * (blob_hash=NULL AND is_dir=0) are recorded in $tombstoned and excluded
 * from the result. */
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
    $s = $db->prepare("SELECT id, dolt_hash, message, created_at FROM fs_commits WHERE branch_id = :b ORDER BY id DESC LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $r = $s->execute();
    $row = $r->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

/* "Current" fs_commit on a branch == the snapshot paired with the
 * branch's current Dolt HEAD. This is the right anchor for "did the
 * file overlay diverge since the last sync?" — fs_last_commit (highest
 * id) is wrong after a reset to an earlier commit. */
function fs_current_commit(SQLite3 $db, int $branch_id, mysqli $dolt, string $branch_name): ?array {
    $head = dolt_head_hash($dolt, $branch_name);
    if ($head === '') return null;
    return fs_find_commit_by_dolt_hash($db, $branch_id, $head);
}

function fs_find_commit_by_dolt_hash(SQLite3 $db, int $branch_id, string $dolt_hash): ?array {
    $s = $db->prepare("SELECT id, dolt_hash, message, created_at FROM fs_commits WHERE branch_id = :b AND dolt_hash = :h LIMIT 1");
    $s->bindValue(':b', $branch_id, SQLITE3_INTEGER);
    $s->bindValue(':h', $dolt_hash, SQLITE3_TEXT);
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

function fs_record_snapshot(SQLite3 $db, int $branch_id, string $dolt_hash, string $message): int {
    $tree = fs_resolve_tree($db, $branch_id);
    $parent = fs_last_commit($db, $branch_id);
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

        /* IMPORTANT: do NOT delete later fs_commits on reset. Dolt's
         * reset --hard only moves the branch ref, the abandoned commits
         * remain reachable via dolt_log + reflog. Symmetric branchfs
         * behavior means the paired fs_commits must stay too — otherwise
         * `reset HEAD~1` followed by `reset <newer-hash>` finds no
         * paired snapshot for the newer hash and the file overlay can't
         * be restored. The "current" fs_commit is determined dynamically
         * by matching the branch's current Dolt HEAD hash. */

        $db->exec('COMMIT');
        return $count;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/* Ask Dolt for HEAD hash on <branch>, without modifying session state. */
function dolt_head_hash(mysqli $c, string $branch): string {
    $esc = $c->real_escape_string($branch);
    $r = $c->query("SELECT hash FROM dolt_branches WHERE name = '$esc' LIMIT 1");
    if (!($r instanceof mysqli_result)) return '';
    $row = $r->fetch_assoc();
    $r->free();
    return $row['hash'] ?? '';
}

switch ($cmd) {

case 'list': {
    branchfs_set_db($DB_PATH);
    $db = sqlite_open($DB_PATH);
    $branches = branchfs_list($db);

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $doltset = [];
    $r = dolt_query($c, "SELECT name FROM dolt_branches");
    if ($r instanceof mysqli_result) {
        while ($row = $r->fetch_assoc()) $doltset[$row['name']] = true;
        $r->free();
    }
    drain($c);
    $c->close();

    printf("%-20s  %-20s  %-8s  %-6s  %s\n", "BRANCH", "PARENT", "FILES", "DOLT", "CREATED");
    printf("%s\n", str_repeat('-', 80));
    foreach ($branches as $b) {
        $files = branchfs_file_count($db, (int)$b['id']);
        $dolt = isset($doltset[$b['name']]) ? 'yes' : 'NO';
        printf("%-20s  %-20s  %-8d  %-6s  %s\n",
            $b['name'],
            $b['parent_branch'] ?? '(root)',
            $files,
            $dolt,
            $b['created_at'] ?? ''
        );
    }
    // Dolt-only branches
    $bfs_names = array_column($branches, 'name');
    foreach ($doltset as $name => $_) {
        if (!in_array($name, $bfs_names, true)) {
            printf("%-20s  %-20s  %-8s  %-6s  %s\n", $name, '(dolt only)', '—', 'yes', '');
        }
    }
    break;
}

case 'create': {
    $name = $pos[1] ?? die_usage("`create` needs a branch name");
    $from = (string)($flags['from'] ?? 'main');
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    if ($name === 'main') die_usage("'main' is reserved");

    branchfs_set_db($DB_PATH);
    branchfs_create_branch($name, $from);
    echo "branchfs: forked '$from' -> '$name'\n";

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc_name = $c->real_escape_string($name);
    $esc_from = $c->real_escape_string($from);
    dolt_query($c, "CALL DOLT_BRANCH('$esc_name', '$esc_from')");
    drain($c);
    echo "dolt:     forked '$from' -> '$name'\n";

    /* Pair the new branch with a root fs_commit so reset has something to
     * land on even before the user commits. Snapshot captures the COW-
     * resolved state, which matches <from>'s current overlay. */
    $head = dolt_head_hash($c, $name);
    if ($head !== '') {
        $db = sqlite_open($DB_PATH);
        $bid = fs_branch_id($db, $name);
        if ($bid > 0 && fs_find_commit_by_dolt_hash($db, $bid, $head) === null) {
            fs_record_snapshot($db, $bid, $head, "branchctl create from '$from'");
            echo "branchfs: initial snapshot recorded (dolt: " . substr($head, 0, 12) . ")\n";
        }
    }
    $c->close();
    echo "\n";
    echo "Visit http://$name.\$BRANCHFS_ROOT_HOST:\$PORT/ to see this branch.\n";
    break;
}

case 'commit': {
    $name = $pos[1] ?? die_usage("`commit` needs a branch name");
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    $msg  = (string)($flags['message'] ?? ("branchctl commit on " . date('c')));

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc_name = $c->real_escape_string($name);
    $esc_msg  = $c->real_escape_string($msg);
    dolt_query($c, "CALL DOLT_CHECKOUT('$esc_name')"); drain($c);
    dolt_query($c, "CALL DOLT_ADD('-A')"); drain($c);

    $dolt_committed = false;
    $r = $c->query("CALL DOLT_COMMIT('-am', '$esc_msg')");
    if ($r === false) {
        if (strpos($c->error, 'nothing to commit') !== false) {
            echo "dolt:     nothing to commit on '$name'\n";
        } else {
            fwrite(STDERR, "branchctl: DOLT_COMMIT failed: " . $c->error . "\n");
            exit(4);
        }
    } else {
        if ($r instanceof mysqli_result) $r->free();
        drain($c);
        echo "dolt:     committed on '$name': $msg\n";
        $dolt_committed = true;
    }

    /* Snapshot the branch's file tree paired with the current Dolt HEAD.
     * We snapshot even when the DB had "nothing to commit" if the file tree
     * diverges from the last fs_commit — otherwise file-side changes could
     * never get recorded through branchctl. */
    $head = dolt_head_hash($c, $name);
    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    if ($bid > 0 && $head !== '') {
        $existing = fs_find_commit_by_dolt_hash($db, $bid, $head);
        $current_digest = fs_tree_digest(fs_resolve_tree($db, $bid));
        $last = fs_last_commit($db, $bid);
        $last_digest = $last ? fs_digest_of_commit($db, (int)$last['id']) : '';

        if ($existing && $existing['dolt_hash'] === $head) {
            if ($current_digest !== fs_digest_of_commit($db, (int)$existing['id'])) {
                echo "branchfs: WARNING: file tree diverges from existing fs_commit at this Dolt hash.\n";
                echo "          Dolt HEAD didn't advance; skipping fs_commit rewrite.\n";
            } else {
                echo "branchfs: no file-side changes.\n";
            }
        } elseif ($dolt_committed || $current_digest !== $last_digest) {
            $cid = fs_record_snapshot($db, $bid, $head, $msg);
            echo "branchfs: snapshot #$cid paired with dolt " . substr($head, 0, 12) . "\n";
        } else {
            echo "branchfs: no file-side changes (skipped snapshot).\n";
        }
    }
    $c->close();
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
    if ($row) {
        $bid = (int)$row[0];
        $db->exec("DELETE FROM files    WHERE branch_id = $bid");
        $db->exec("DELETE FROM branches WHERE id        = $bid");
        echo "branchfs: deleted overlay '$name'\n";
    } else {
        echo "branchfs: no overlay named '$name'\n";
    }

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc = $c->real_escape_string($name);
    // Idempotent: tolerate a missing Dolt branch.
    mysqli_report(MYSQLI_REPORT_OFF);
    @$c->query("CALL DOLT_CHECKOUT('main')"); drain($c);
    $r = @$c->query("CALL DOLT_BRANCH('-D', '$esc')");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($r === false) {
        echo "dolt:     no branch named '$name' (or already deleted)\n";
    } else {
        if ($r instanceof mysqli_result) $r->free();
        drain($c);
        echo "dolt:     deleted branch '$name'\n";
    }
    $c->close();
    break;
}

case 'show': {
    $name = $pos[1] ?? die_usage("`show` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch name: $name");

    branchfs_set_db($DB_PATH);
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
    }

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc = $c->real_escape_string($name);
    $r = $c->query("SELECT hash, latest_committer, latest_commit_date, latest_commit_message FROM dolt_branches WHERE name = '$esc'");
    if ($r instanceof mysqli_result) {
        $dr = $r->fetch_assoc();
        $r->free();
        if ($dr) {
            echo "dolt: $name\n";
            echo "  head   : " . substr($dr['hash'] ?? '', 0, 12) . "\n";
            echo "  author : " . ($dr['latest_committer'] ?? '') . "\n";
            echo "  when   : " . ($dr['latest_commit_date'] ?? '') . "\n";
            echo "  message: " . ($dr['latest_commit_message'] ?? '') . "\n";
        } else {
            echo "dolt: no branch named '$name'\n";
        }
    }
    drain($c);
    $c->close();
    break;
}

case 'log': {
    $name = $pos[1] ?? die_usage("`log` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch name: $name");
    $limit = (int)($flags['n'] ?? $flags['limit'] ?? 20);
    if ($limit < 1) $limit = 20;
    if ($limit > 500) $limit = 500;

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc = $c->real_escape_string($name);
    /* DOLT_LOG takes a branch or revspec; use the --branch flag form via
     * the system-table alias for safety and easy output.
     * Columns: commit_hash, committer, email, date, message. */
    $sql = "SELECT commit_hash, committer, date, message "
         . "FROM dolt_log('$esc') LIMIT $limit";
    $r = dolt_query($c, $sql);
    $hashes = [];
    $rows   = [];
    if ($r instanceof mysqli_result) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = $row;
            $hashes[$row['commit_hash']] = true;
        }
        $r->free();
    }
    drain($c);
    $c->close();

    /* Build fs-commit presence map so log can show which Dolt commits
     * have a paired file snapshot (=> `reset` there will restore files). */
    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    $paired = [];
    if ($bid > 0 && !empty($hashes)) {
        $in = "'" . implode("','",
            array_map(fn($h) => SQLite3::escapeString($h), array_keys($hashes))) . "'";
        $r2 = $db->query("SELECT dolt_hash FROM fs_commits WHERE branch_id = $bid AND dolt_hash IN ($in)");
        while ($row = $r2->fetchArray(SQLITE3_ASSOC)) $paired[$row['dolt_hash']] = true;
    }

    /* Full 32-char hash: reset requires the full hash, not a prefix. */
    printf("%-2s  %-34s  %-19s  %-12s  %s\n", "FS", "COMMIT", "WHEN", "AUTHOR", "MESSAGE");
    printf("%s\n", str_repeat('-', 105));
    foreach ($rows as $row) {
        printf("%-2s  %-34s  %-19s  %-12s  %s\n",
            isset($paired[$row['commit_hash']]) ? '*' : ' ',
            $row['commit_hash'] ?? '',
            substr($row['date'] ?? '', 0, 19),
            substr($row['committer'] ?? '', 0, 12),
            trim($row['message'] ?? '')
        );
    }
    echo "\n  '*' indicates a paired branchfs snapshot — reset here restores files.\n";
    break;
}

case 'diff': {
    $a = $pos[1] ?? die_usage("`diff` needs two branch names");
    $b = $pos[2] ?? die_usage("`diff` needs two branch names");
    if (!valid_branch_name($a) && $a !== 'main') die_usage("invalid branch: $a");
    if (!valid_branch_name($b) && $b !== 'main') die_usage("invalid branch: $b");

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $ea = $c->real_escape_string($a);
    $eb = $c->real_escape_string($b);
    /* Per-table summary (row counts of adds/modifications/deletions). */
    $sql = "SELECT table_name, rows_added, rows_deleted, rows_modified "
         . "FROM dolt_diff_stat('$ea', '$eb')";
    $r = dolt_query($c, $sql);
    $any = false;
    if ($r instanceof mysqli_result) {
        printf("dolt: rows changed '%s' -> '%s'\n", $a, $b);
        printf("%-30s  %-7s  %-7s  %-7s\n", "TABLE", "ADDED", "DELETED", "MOD");
        printf("%s\n", str_repeat('-', 60));
        while ($row = $r->fetch_assoc()) {
            $add = (int)($row['rows_added'] ?? 0);
            $del = (int)($row['rows_deleted'] ?? 0);
            $mod = (int)($row['rows_modified'] ?? 0);
            if ($add + $del + $mod === 0) continue;
            $any = true;
            printf("%-30s  %-7d  %-7d  %-7d\n", $row['table_name'] ?? '', $add, $del, $mod);
        }
        $r->free();
    }
    if (!$any) echo "  (no row-level differences)\n";
    drain($c);
    $c->close();

    /* File-side: summarise via branchfs overlay. */
    $db = sqlite_open($DB_PATH);
    $aid = (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($a) . "'");
    $bid = (int)$db->querySingle("SELECT id FROM branches WHERE name = '" . $db->escapeString($b) . "'");
    echo "\nbranchfs: overlay file counts\n";
    if ($aid) printf("  %-40s  %d files\n", "'$a' overlay", branchfs_file_count($db, $aid));
    if ($bid) printf("  %-40s  %d files\n", "'$b' overlay", branchfs_file_count($db, $bid));
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
    /* Reuse the existing merge implementation so we have one code path.
     * merge.php signature: php merge.php <from> <into> [db-path] [dolt-db] */
    echo "branchctl: invoking scripts/merge.php ...\n";
    $argv_forward = [
        escapeshellarg($from),
        escapeshellarg($into),
        escapeshellarg($DB_PATH),
        escapeshellarg("$DOLTDB"),
    ];
    $ext = ini_get('extension_dir') ?? '';
    $so = realpath(__DIR__ . '/../ext/branchfs.so');
    $php_bin = PHP_BINARY;
    $cmdline = sprintf(
        'DOLT_HOST=%s DOLT_PORT=%d DOLT_USER=%s DOLT_PASSWORD=%s '
        . '%s -d extension=%s -d display_errors=Off -d display_startup_errors=Off %s %s',
        escapeshellarg($HOST),
        $PORT,
        escapeshellarg($USER),
        escapeshellarg($PASS),
        escapeshellarg($php_bin),
        escapeshellarg($so ?: 'branchfs.so'),
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
    $name = $pos[1] ?? die_usage("`reset` needs a branch name");
    $commit = $pos[2] ?? die_usage("`reset` needs a commit hash or ref (e.g. HEAD~1)");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");
    if (!preg_match('/^[A-Za-z0-9_\-\^~\/]{1,128}$/', $commit)) {
        die_usage("invalid commit ref: $commit");
    }
    $force = !empty($flags['force']);

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);

    /* Compare overlay against the snapshot paired with the branch's
     * CURRENT Dolt HEAD (not "highest fs_commit id" — that anchor breaks
     * after an earlier reset moved Dolt HEAD backwards). */
    if ($bid > 0 && !$force) {
        $current_snap = fs_current_commit($db, $bid, $c, $name);
        if ($current_snap) {
            $tree_now    = fs_tree_digest(fs_resolve_tree($db, $bid));
            $snap_digest = fs_digest_of_commit($db, (int)$current_snap['id']);
            if ($tree_now !== $snap_digest) {
                fwrite(STDERR,
                    "branchctl: '$name' has file-side changes since the snapshot at HEAD.\n"
                  . "           Reset would discard them. Run `branchctl commit $name` first,\n"
                  . "           or pass --force to discard.\n");
                $c->close();
                exit(6);
            }
        }
    }

    $esc_name = $c->real_escape_string($name);
    $esc_commit = $c->real_escape_string($commit);
    dolt_query($c, "CALL DOLT_CHECKOUT('$esc_name')"); drain($c);
    dolt_query($c, "CALL DOLT_RESET('--hard', '$esc_commit')"); drain($c);

    /* Dolt accepts HEAD~N / branchname / full hash; resolve the result to a
     * concrete hash so we can look up the paired fs_commit. */
    $resolved = dolt_head_hash($c, $name);
    echo "dolt:     '$name' hard-reset to '$commit' (HEAD is now " . substr($resolved, 0, 12) . ")\n";

    $fs = $bid > 0 && $resolved !== '' ? fs_find_commit_by_dolt_hash($db, $bid, $resolved) : null;
    if ($fs) {
        $n = fs_restore_snapshot($db, $bid, (int)$fs['id']);
        echo "branchfs: restored $n files from fs_commit #{$fs['id']} ({$fs['message']})\n";
    } else {
        echo "branchfs: no paired fs_commit for this Dolt hash — file overlay unchanged.\n";
        echo "          (reset to a commit that was made through `branchctl commit` to restore files.)\n";
    }
    $c->close();
    break;
}

case 'rollback': {
    $name = $pos[1] ?? die_usage("`rollback` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");
    $force = !empty($flags['force']);

    $db = sqlite_open($DB_PATH);
    $bid = fs_branch_id($db, $name);
    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);

    if ($bid > 0 && !$force) {
        $current_snap = fs_current_commit($db, $bid, $c, $name);
        if ($current_snap) {
            $tree_now    = fs_tree_digest(fs_resolve_tree($db, $bid));
            $snap_digest = fs_digest_of_commit($db, (int)$current_snap['id']);
            if ($tree_now !== $snap_digest) {
                fwrite(STDERR,
                    "branchctl: '$name' has file-side changes since the snapshot at HEAD.\n"
                  . "           Rollback would discard them. Run `branchctl commit $name`\n"
                  . "           first, or pass --force to discard.\n");
                $c->close();
                exit(6);
            }
        }
    }

    $esc = $c->real_escape_string($name);
    dolt_query($c, "CALL DOLT_CHECKOUT('$esc')"); drain($c);
    dolt_query($c, "CALL DOLT_RESET('--hard', 'HEAD~1')"); drain($c);
    $resolved = dolt_head_hash($c, $name);
    echo "dolt:     '$name' rolled back (HEAD is now " . substr($resolved, 0, 12) . ")\n";

    $fs = $bid > 0 && $resolved !== '' ? fs_find_commit_by_dolt_hash($db, $bid, $resolved) : null;
    if ($fs) {
        $n = fs_restore_snapshot($db, $bid, (int)$fs['id']);
        echo "branchfs: restored $n files from fs_commit #{$fs['id']} ({$fs['message']})\n";
    } else {
        echo "branchfs: no paired fs_commit for HEAD~1 — file overlay unchanged.\n";
    }
    $c->close();
    break;
}

default:
    die_usage("unknown command: $cmd");
}
