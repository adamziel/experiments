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
  branchctl reset   <name>  <commit-hash-or-ref>
  branchctl rollback <name>

Flags:
  --db <path>          override BRANCHFS_DB (default: /tmp/branchfs-dev/branchfs.db)
  --dolt-host <host>   override DOLT_HOST (default: 127.0.0.1)
  --dolt-port <port>   override DOLT_PORT (default: 13306)

Workflow:
  create   forks BOTH the branchfs overlay and the Dolt branch.
  commit   commits any pending DB writes on <name> (files are committed
             implicitly on each write).
  log      shows the Dolt commit history of <name>.
  diff     summarises row differences between two branches.
  merge    runs scripts/merge.php: 3-way file merge + DOLT_MERGE, result
             lands on <target>.
  reset    hard-resets <name> to <commit> (DOLT_RESET --hard). Files are
             NOT rewound — we don't have per-file history yet; treat
             reset as "DB side only".
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
    return new SQLite3($path, SQLITE3_OPEN_READWRITE);
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
    $c->close();
    echo "\n";
    echo "Visit http://$name.\$BRANCHFS_ROOT_HOST:\$PORT/ to see this branch.\n";
    break;
}

case 'commit': {
    $name = $pos[1] ?? die_usage("`commit` needs a branch name");
    if (!valid_branch_name($name)) die_usage("invalid branch name: $name");
    $msg  = (string)($flags['message'] ?? ("branchctl commit on " . date('c')));

    // Branchfs writes are auto-committed on disk; here we just commit Dolt.
    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc_name = $c->real_escape_string($name);
    $esc_msg  = $c->real_escape_string($msg);
    dolt_query($c, "CALL DOLT_CHECKOUT('$esc_name')"); drain($c);
    dolt_query($c, "CALL DOLT_ADD('-A')"); drain($c);
    $r = $c->query("CALL DOLT_COMMIT('-am', '$esc_msg')");
    if ($r === false) {
        // nothing to commit is fine
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
    if ($r instanceof mysqli_result) {
        /* Full 32-char hash: branchctl reset requires the full hash, not a
         * prefix. Keep copy-pasteable. */
        printf("%-34s  %-19s  %-12s  %s\n", "COMMIT", "WHEN", "AUTHOR", "MESSAGE");
        printf("%s\n", str_repeat('-', 100));
        while ($row = $r->fetch_assoc()) {
            printf("%-34s  %-19s  %-12s  %s\n",
                $row['commit_hash'] ?? '',
                substr($row['date'] ?? '', 0, 19),
                substr($row['committer'] ?? '', 0, 12),
                trim($row['message'] ?? '')
            );
        }
        $r->free();
    }
    drain($c);
    $c->close();
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
    /* Allow a restricted set of Dolt-friendly refspecs.
     * Accept: hex hashes, HEAD, HEAD^, HEAD~N, branchname. */
    if (!preg_match('/^[A-Za-z0-9_\-\^~\/]{1,128}$/', $commit)) {
        die_usage("invalid commit ref: $commit");
    }

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc_name = $c->real_escape_string($name);
    $esc_commit = $c->real_escape_string($commit);
    dolt_query($c, "CALL DOLT_CHECKOUT('$esc_name')"); drain($c);
    dolt_query($c, "CALL DOLT_RESET('--hard', '$esc_commit')"); drain($c);
    echo "dolt:     '$name' hard-reset to '$commit'\n";
    echo "branchfs: file-side overlay NOT rewound (no per-file history yet)\n";
    echo "          if you need files restored, re-import them or merge from a known-good branch.\n";
    $c->close();
    break;
}

case 'rollback': {
    $name = $pos[1] ?? die_usage("`rollback` needs a branch name");
    if (!valid_branch_name($name) && $name !== 'main') die_usage("invalid branch: $name");

    $c = connect_dolt($HOST, $PORT, $USER, $PASS, $DOLTDB);
    $esc = $c->real_escape_string($name);
    dolt_query($c, "CALL DOLT_CHECKOUT('$esc')"); drain($c);
    dolt_query($c, "CALL DOLT_RESET('--hard', 'HEAD~1')"); drain($c);
    echo "dolt:     '$name' rolled back one commit (HEAD~1)\n";
    echo "branchfs: file-side overlay NOT rewound.\n";
    $c->close();
    break;
}

default:
    die_usage("unknown command: $cmd");
}
