<?php
/**
 * Git smart-HTTP server for BranchFS.
 *
 * Bridges the branchfs file overlay + Dolt database with the Git protocol
 * via the WordPress php-toolkit's GitEndpoint.
 *
 * Expected globals from router.php: $db_path, $wp_root, $branch
 * Expected $_SERVER vars: REQUEST_METHOD, REQUEST_URI, HTTP_HOST
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/sqlite_exporter.php';
require_once __DIR__ . '/sqlite_importer.php';

use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\HttpServer\Response\StreamingResponseWriter;

// Bump when the on-the-wire shape of the clone changes (e.g. a different
// DB layout). The push side rejects mismatches with a clear error.
const SQLITE_CLONE_SCHEMA_VERSION = 1;
// Paths emitted on every clone that the server owns — any push changes
// to these are ignored, since they're regenerated from scratch next
// pull. Covers the db.php drop-in, the vendored plugin tree, the
// SQLite DB file, and the metadata stub.
const SQLITE_CLONE_SERVER_OWNED_PREFIXES = [
    'wordpress/wp-content/db.php',
    'wordpress/wp-content/database/',
    'wordpress/wp-content/plugins/sqlite-database-integration/',
];
const SQLITE_CLONE_META_FILE = 'db-meta.json';

function git_server_handle(string $db_path, string $wp_root, string $git_path, string $query_string): void {
    ini_set('memory_limit', '512M');
    set_time_limit(300);
    $dolt_host = getenv('DOLT_HOST') ?: '127.0.0.1';
    $dolt_port = (int)(getenv('DOLT_PORT') ?: '13306');
    $dolt_user = getenv('DOLT_USER') ?: 'root';
    $dolt_pass = getenv('DOLT_PASSWORD') ?: '';
    $dolt_db   = getenv('DOLT_DB') ?: 'wordpress';

    $sqlite = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
    $sqlite->busyTimeout(5000);

    // Include branchctl helpers (fs_resolve_tree, fs_record_snapshot, etc.)
    require_once __DIR__ . '/helpers.php';

    $dolt = @new mysqli($dolt_host, $dolt_user, $dolt_pass, $dolt_db, $dolt_port);
    if ($dolt->connect_error) {
        http_response_code(503);
        echo "Cannot connect to Dolt: " . $dolt->connect_error . "\n";
        return;
    }

    // Determine the git endpoint path
    // git_path is like /git-upload-pack or /info/refs
    $endpoint_path = $git_path;
    if ($query_string) {
        $endpoint_path .= '?' . $query_string;
    }

    // Per-request cache dir — two concurrent clones must not trample each
    // other's working tree (finding #2). Rebuild the repo from scratch each
    // request; the heavy work is Dolt export, not filesystem layout.
    $cache_root = sys_get_temp_dir() . '/branchfs-git-' . bin2hex(random_bytes(8));
    mkdir($cache_root, 0700, true);
    register_shutdown_function(function() use ($cache_root) {
        if (is_dir($cache_root)) {
            git_rmrf($cache_root);
        }
    });
    $repo_dir = $cache_root . '/repo';

    // Determine request type
    $is_post_receive = ($git_path === '/git-receive-pack');
    $is_receive_discovery = (strpos($endpoint_path, 'service=git-receive-pack') !== false);

    // Auth only required on actual push (POST /git-receive-pack)
    $auth_user = null;
    if ($is_post_receive) {
        $auth_user = git_check_auth();
        if ($auth_user === null) {
            header('WWW-Authenticate: Basic realm="BranchFS Git"');
            http_response_code(401);
            echo "Authentication required for push\n";
            $dolt->close();
            return;
        }
    }

    // Capture pre-push state. On post_receive we buffer all output so a
    // mid-flight failure can replace the success response with a rejection
    // AND roll branchfs + Dolt back to their pre-push hashes (finding #3).
    $pre_state = null;
    if ($is_post_receive) {
        $pre_state = git_capture_pre_state($sqlite, $dolt);
        ob_start();
    }

    // Build the repository from branchfs state
    git_build_repository($repo_dir, $sqlite, $dolt, $dolt_db);

    // Create the toolkit repository and endpoint
    $fs = LocalFilesystem::create($repo_dir);
    $repo = new GitRepository($fs, ['default_branch' => 'main']);
    $endpoint = new GitEndpoint($repo);

    // Read request body
    $request_bytes = file_get_contents('php://input');

    // Handle the request
    $response = new StreamingResponseWriter();
    try {
        $endpoint->handle_request($endpoint_path, $request_bytes, $response);
    } catch (\Throwable $e) {
        error_log("Git server error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    // After push: apply changes back to branchfs + Dolt, transactionally.
    if ($is_post_receive && $auth_user !== null) {
        $push_error = null;
        try {
            git_process_push($repo_dir, $fs, $repo, $sqlite, $dolt, $dolt_db, $auth_user, $pre_state);
        } catch (\Throwable $e) {
            $push_error = $e->getMessage();
            error_log("Push processing error: $push_error\n" . $e->getTraceAsString());
        }

        if ($push_error !== null) {
            // Roll back branchfs + Dolt, replace the buffered (likely-success)
            // response with an explicit HTTP 500 so the git client sees
            // "! [remote rejected]".
            git_rollback_to_state($pre_state, $sqlite, $dolt);
            if (ob_get_level() > 0) ob_end_clean();
            http_response_code(500);
            header('Content-Type: text/plain');
            $short = substr(str_replace(["\n", "\r"], ' ', $push_error), 0, 500);
            echo "branchfs: push rejected: $short\n";
            $dolt->close();
            return;
        }

        // Success: flush the buffered git protocol response to the client.
        if (ob_get_level() > 0) ob_end_flush();
    }

    $dolt->close();
}

/**
 * Capture per-branch pre-push state for transactional rollback (finding #3).
 * Records current Dolt HEAD hash for every branchfs branch. Branches that
 * don't exist yet are implicitly NEW and will be deleted on rollback.
 */
function git_capture_pre_state(SQLite3 $sqlite, mysqli $dolt): array {
    $state = ['branches' => []];
    $r = $sqlite->query("SELECT id, name FROM branches");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
        $name = $row['name'];
        $bid  = (int)$row['id'];
        $dolt_hash = git_dolt_head_hash($dolt, $name);
        $fs_commit = git_fs_find_commit_by_dolt_hash($sqlite, $bid, $dolt_hash);
        $state['branches'][$name] = [
            'bid'        => $bid,
            'dolt_hash'  => $dolt_hash,
            'fs_commit'  => $fs_commit ? (int)$fs_commit['id'] : null,
        ];
    }
    return $state;
}

/**
 * Roll branchfs + Dolt back to the captured pre-push state (finding #3).
 * For branches that existed: DOLT_RESET --hard to pre_hash, restore fs files.
 * For branches created by the push but not in pre_state: delete them entirely.
 */
function git_rollback_to_state(?array $state, SQLite3 $sqlite, mysqli $dolt): void {
    if (!$state) return;

    // 1) Delete NEW branches that weren't in pre_state.
    $r = $sqlite->query("SELECT id, name FROM branches");
    $current = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $current[] = $row;
    foreach ($current as $row) {
        if (!isset($state['branches'][$row['name']])) {
            $bid = (int)$row['id'];
            $sqlite->exec("DELETE FROM fs_commit_files WHERE commit_id IN (SELECT id FROM fs_commits WHERE branch_id=$bid)");
            $sqlite->exec("DELETE FROM fs_commits WHERE branch_id = $bid");
            $sqlite->exec("DELETE FROM files WHERE branch_id = $bid");
            $sqlite->exec("DELETE FROM branches WHERE id = $bid");
            $esc = $dolt->real_escape_string($row['name']);
            @$dolt->query("CALL DOLT_CHECKOUT('main')"); git_drain($dolt);
            @$dolt->query("CALL DOLT_BRANCH('-D', '$esc')"); git_drain($dolt);
        }
    }

    // 2) Reset EXISTING branches to their pre-push Dolt hash + restore files.
    foreach ($state['branches'] as $name => $b) {
        if (!$b['dolt_hash']) continue;
        $esc = $dolt->real_escape_string($name);
        @$dolt->query("CALL DOLT_CHECKOUT('$esc')"); git_drain($dolt);
        $esc_hash = $dolt->real_escape_string($b['dolt_hash']);
        @$dolt->query("CALL DOLT_RESET('--hard', '$esc_hash')"); git_drain($dolt);

        if ($b['fs_commit'] !== null) {
            git_fs_restore_from_commit($sqlite, (int)$b['bid'], (int)$b['fs_commit']);
        }
    }
}

/**
 * Restore branch overlay from a specific fs_commit id (rollback helper).
 * Intentionally independent of branchctl's helper so the web request path
 * has no CLI dependency.
 */
function git_fs_restore_from_commit(SQLite3 $db, int $branch_id, int $commit_id): void {
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec("DELETE FROM files WHERE branch_id = $branch_id");
        $ins = $db->prepare(
            "INSERT INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) "
          . "VALUES (:b, :p, :bh, :md, :mt, :d)"
        );
        $r = $db->query("SELECT path, blob_hash, mode, mtime, is_dir FROM fs_commit_files WHERE commit_id = $commit_id");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $ins->bindValue(':b',  $branch_id, SQLITE3_INTEGER);
            $ins->bindValue(':p',  $row['path'], SQLITE3_TEXT);
            $ins->bindValue(':bh', $row['blob_hash'] ?? null,
                $row['blob_hash'] ? SQLITE3_TEXT : SQLITE3_NULL);
            $ins->bindValue(':md', (int)($row['mode']  ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':mt', (int)($row['mtime'] ?? 0), SQLITE3_INTEGER);
            $ins->bindValue(':d',  (int)($row['is_dir']?? 0), SQLITE3_INTEGER);
            $ins->execute();
            $ins->reset();
        }
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * Reserved names that conflict with HTTP routing or well-known subdomains.
 * Pushes creating a branch with any of these names are rejected with 400.
 */
function git_reserved_branch_names(): array {
    return ['www', 'admin', 'api', 'mail', 'localhost', 'wp'];
}

/**
 * Validate push auth against BRANCHFS_GIT_USER / BRANCHFS_GIT_PASSWORD_HASH.
 * Defaults to admin/admin for dev. BRANCHFS_PROD=1 forces non-default creds.
 *
 * Returns the authenticated username on success, null on failure.
 */
function git_check_auth(): ?string {
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        return null;
    }

    $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string)($_SERVER['PHP_AUTH_PW']   ?? '');

    $env_user = getenv('BRANCHFS_GIT_USER')          ?: 'admin';
    $env_hash = getenv('BRANCHFS_GIT_PASSWORD_HASH') ?: '';

    // In prod we REFUSE the built-in default creds. Better to explicitly
    // break than silently accept admin/admin.
    $prod = getenv('BRANCHFS_PROD') === '1';
    if ($prod) {
        if (!getenv('BRANCHFS_GIT_USER') || !getenv('BRANCHFS_GIT_PASSWORD_HASH')) {
            error_log('BRANCHFS_PROD=1 but BRANCHFS_GIT_USER/BRANCHFS_GIT_PASSWORD_HASH not both set — refusing push');
            return null;
        }
    }

    if ($env_hash !== '') {
        if ($user === $env_user && password_verify($pass, $env_hash)) {
            return $user;
        }
        return null;
    }

    // Dev default: admin / admin. Rejected when BRANCHFS_PROD=1 (checked above).
    if ($user === 'admin' && $pass === 'admin') {
        return $user;
    }
    return null;
}

/**
 * Build a Git repository on disk from branchfs + Dolt state.
 */
function git_build_repository(string $repo_dir, SQLite3 $sqlite, mysqli $dolt, string $dolt_db): void {
    // Clean and recreate
    if (is_dir($repo_dir)) {
        git_rmrf($repo_dir);
    }
    mkdir($repo_dir, 0755, true);

    // Let GitRepository handle its own initialization
    $fs = LocalFilesystem::create($repo_dir);
    $repo = new GitRepository($fs, ['default_branch' => 'main']);
    $repo->set_config_value(['user', 'name'], 'BranchFS');
    $repo->set_config_value(['user', 'email'], 'branchfs@local');

    $wp_tables = [
        'wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta',
        'wp_comments', 'wp_commentmeta', 'wp_terms', 'wp_termmeta',
        'wp_term_relationships', 'wp_term_taxonomy', 'wp_links',
    ];
    $table_pk = [
        'wp_options' => 'option_id',
        'wp_posts' => 'ID',
        'wp_postmeta' => 'meta_id',
        'wp_users' => 'ID',
        'wp_usermeta' => 'umeta_id',
        'wp_comments' => 'comment_ID',
        'wp_commentmeta' => 'meta_id',
        'wp_terms' => 'term_id',
        'wp_termmeta' => 'meta_id',
        'wp_term_relationships' => 'object_id, term_taxonomy_id',
        'wp_term_taxonomy' => 'term_taxonomy_id',
        'wp_links' => 'link_id',
    ];

    // Get all branchfs branches
    $branches_result = $sqlite->query("SELECT id, name FROM branches ORDER BY name");
    $branches = [];
    while ($row = $branches_result->fetchArray(SQLITE3_ASSOC)) {
        $branches[] = $row;
    }

    // Build commits for each branch
    foreach ($branches as $br) {
        $branch_name = $br['name'];
        $branch_id = (int)$br['id'];

        // Get Dolt commit history for this branch
        $esc = $dolt->real_escape_string($branch_name);
        $dolt_commits = [];
        $r = $dolt->query("SELECT commit_hash, committer, date, message FROM dolt_log('$esc') ORDER BY date ASC");
        if ($r instanceof mysqli_result) {
            while ($row = $r->fetch_assoc()) {
                $dolt_commits[] = $row;
            }
            $r->free();
        }
        git_drain($dolt);

        if (empty($dolt_commits)) continue;

        // Get paired fs_commits for this branch
        $fs_commits = [];
        $fcr = $sqlite->query("SELECT id, dolt_hash, message, created_at FROM fs_commits WHERE branch_id = $branch_id ORDER BY id ASC");
        while ($row = $fcr->fetchArray(SQLITE3_ASSOC)) {
            $fs_commits[$row['dolt_hash']] = $row;
        }

        // Auto-snapshot current state if needed
        git_auto_snapshot($sqlite, $dolt, $branch_name, $branch_id);

        // Re-fetch fs_commits after auto-snapshot
        $fs_commits = [];
        $fcr = $sqlite->query("SELECT id, dolt_hash, message, created_at FROM fs_commits WHERE branch_id = $branch_id ORDER BY id ASC");
        while ($row = $fcr->fetchArray(SQLITE3_ASSOC)) {
            $fs_commits[$row['dolt_hash']] = $row;
        }

        // Build git commits: for each Dolt commit that has a paired fs_commit,
        // create a git commit. For the latest commit, always include it.
        $parent_git_hash = null;
        $tip_hash = null;

        foreach ($dolt_commits as $dc) {
            $dolt_hash = $dc['commit_hash'];
            $has_fs = isset($fs_commits[$dolt_hash]);
            $is_latest = ($dc === end($dolt_commits));

            if (!$has_fs && !$is_latest) continue;

            // Build the tree for this commit
            $updates = [];

            // wordpress/ files from fs_commit_files or current resolved tree
            if ($has_fs) {
                $fc_id = (int)$fs_commits[$dolt_hash]['id'];
                $tree_rows = $sqlite->query("SELECT path, blob_hash FROM fs_commit_files WHERE commit_id = $fc_id AND is_dir = 0 AND blob_hash IS NOT NULL");
            } else {
                // Use current resolved tree
                $tree_data = git_fs_resolve_tree($sqlite, $branch_id);
                $tree_rows = null;
            }

            if ($tree_rows) {
                while ($fr = $tree_rows->fetchArray(SQLITE3_ASSOC)) {
                    $blob_data = git_get_blob_data($sqlite, $fr['blob_hash']);
                    if ($blob_data !== null) {
                        $updates['wordpress/' . $fr['path']] = $blob_data;
                    }
                }
            } else {
                foreach ($tree_data as $path => $entry) {
                    if ($entry['is_dir']) continue;
                    $blob_data = git_get_blob_data($sqlite, $entry['blob_hash']);
                    if ($blob_data !== null) {
                        $updates['wordpress/' . $path] = $blob_data;
                    }
                }
            }

            // DB as a single SQLite file + drop-in + vendored plugin.
            // This makes the clone self-booting under the WordPress SQLite
            // Database Integration plugin (see `git_clone_db_artifacts`).
            $db_artifacts = git_clone_db_artifacts($dolt, $branch_name, $dolt_hash, $wp_tables);
            foreach ($db_artifacts as $path => $content) {
                $updates[$path] = $content;
            }

            // Create the git commit
            $commit_date = $dc['date'] ?? gmdate('Y-m-d H:i:s');
            $timestamp = strtotime($commit_date);
            if ($timestamp === false) $timestamp = time();
            $date_str = $timestamp . ' +0000';

            $author_name = $dc['committer'] ?? 'BranchFS';
            $author = $author_name . ' <' . $author_name . '@branchfs>';

            $message = $dc['message'] ?? 'BranchFS commit';
            // Include Dolt hash reference
            $message = trim($message) . "\n\nDolt-Commit: " . $dolt_hash;

            $commit_options = [
                'commit' => [
                    'message' => $message,
                    'author' => $author,
                    'author_date' => $date_str,
                    'committer' => $author,
                    'committer_date' => $date_str,
                ],
                'updates' => $updates,
            ];

            if ($parent_git_hash !== null) {
                $commit_options['commit']['parents'] = [$parent_git_hash];
            } else {
                $commit_options['commit']['parents'] = [];
            }

            // Checkout this branch before committing. If the ref doesn't
            // exist yet (every non-default branch), initialize it to
            // NULL_HASH first — get_branch_tip('HEAD') throws on unborn
            // refs, and GitRepository only auto-initializes the default
            // branch. Without this, the second branch in the loop
            // (alphabetically after the default) blows up info/refs with
            // "Branch file not found: refs/heads/<name>".
            $ref_path = "refs/heads/$branch_name";
            if ($parent_git_hash !== null) {
                $repo->set_branch_tip($ref_path, $parent_git_hash);
            } elseif (!$fs->is_file($ref_path)) {
                $repo->set_branch_tip($ref_path, Commit::NULL_HASH);
            }
            $repo->checkout($ref_path);

            $git_hash = $repo->commit($commit_options);
            $parent_git_hash = $git_hash;
            $tip_hash = $git_hash;
        }

        if ($tip_hash !== null) {
            $repo->set_branch_tip("refs/heads/$branch_name", $tip_hash);
        }
    }

    // Set HEAD to main
    file_put_contents($repo_dir . '/HEAD', "ref: refs/heads/main\n");
}

/**
 * Auto-snapshot + Dolt commit if the overlay has diverged from the last fs_commit.
 */
function git_auto_snapshot(SQLite3 $sqlite, mysqli $dolt, string $branch_name, int $branch_id): void {
    $last = git_fs_last_commit($sqlite, $branch_id);
    $tree = git_fs_resolve_tree($sqlite, $branch_id);
    $current_digest = git_fs_tree_digest($tree);

    if ($last) {
        $last_digest = git_fs_digest_of_commit($sqlite, (int)$last['id']);
        if ($current_digest === $last_digest) {
            return; // No divergence
        }
    }

    // Dolt commit first — tolerate "nothing to commit" (happens when the
    // overlay diverged but no DB rows moved, e.g. second clone after WP
    // settled). We still record the fs_commit below against the unchanged
    // Dolt HEAD so the git tree stays paired.
    $esc = $dolt->real_escape_string($branch_name);
    $dolt->query("CALL DOLT_CHECKOUT('$esc')");
    git_drain($dolt);
    $dolt->query("CALL DOLT_ADD('-A')");
    git_drain($dolt);
    mysqli_report(MYSQLI_REPORT_OFF);
    $r = @$dolt->query("CALL DOLT_COMMIT('-am', 'Auto-snapshot for git')");
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($r instanceof mysqli_result) $r->free();
    git_drain($dolt);

    // Get current HEAD hash
    $head = git_dolt_head_hash($dolt, $branch_name);
    if ($head !== '') {
        $existing = git_fs_find_commit_by_dolt_hash($sqlite, $branch_id, $head);
        if ($existing === null) {
            git_fs_record_snapshot($sqlite, $branch_id, $head, 'Auto-snapshot for git');
        }
    }
}

/**
 * Process push: apply received git changes back to branchfs + Dolt.
 * Throws \RuntimeException on unrecoverable errors; the caller rolls back.
 */
function git_process_push(string $repo_dir, $fs, GitRepository $repo, SQLite3 $sqlite, mysqli $dolt, string $dolt_db, string $auth_user, ?array $pre_state = null): void {
    $wp_tables = [
        'wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta',
        'wp_comments', 'wp_commentmeta', 'wp_terms', 'wp_termmeta',
        'wp_term_relationships', 'wp_term_taxonomy', 'wp_links',
    ];
    $table_pk = [
        'wp_options' => 'option_id',
        'wp_posts' => 'ID',
        'wp_postmeta' => 'meta_id',
        'wp_users' => 'ID',
        'wp_usermeta' => 'umeta_id',
        'wp_comments' => 'comment_ID',
        'wp_commentmeta' => 'meta_id',
        'wp_terms' => 'term_id',
        'wp_termmeta' => 'meta_id',
        'wp_term_relationships' => 'object_id, term_taxonomy_id',
        'wp_term_taxonomy' => 'term_taxonomy_id',
        'wp_links' => 'link_id',
    ];

    $reserved = git_reserved_branch_names();

    $refs_dir = $repo_dir . '/refs/heads';
    if (!is_dir($refs_dir)) return;

    foreach (scandir($refs_dir) as $ref_file) {
        if ($ref_file[0] === '.') continue;
        $branch_name = $ref_file;
        $tip_hash = trim(file_get_contents($refs_dir . '/' . $ref_file));

        if (Commit::is_null_hash($tip_hash)) continue;

        // Reject reserved names that would conflict with HTTP routing.
        if (in_array(strtolower($branch_name), $reserved, true)) {
            throw new \RuntimeException(
                "refusing to push to reserved branch name '$branch_name' (conflicts with routing)"
            );
        }

        // Ensure the branchfs branch exists
        $branch_id = git_fs_branch_id($sqlite, $branch_name);
        $branch_is_new = ($branch_id === 0);
        if ($branch_is_new) {
            if (extension_loaded('branchfs')) {
                $db_path_env = getenv('BRANCHFS_DB');
                if ($db_path_env) {
                    branchfs_set_db($db_path_env);
                    branchfs_create_branch($branch_name, 'main');
                }
            }
            $esc = $dolt->real_escape_string($branch_name);
            // Tolerate pre-existing Dolt branch (from a previous attempt).
            mysqli_report(MYSQLI_REPORT_OFF);
            @$dolt->query("CALL DOLT_BRANCH('$esc', 'main')");
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            git_drain($dolt);

            $branch_id = git_fs_branch_id($sqlite, $branch_name);
            if ($branch_id === 0) {
                throw new \RuntimeException("cannot create branchfs branch '$branch_name'");
            }
        }

        // Read the pushed commit
        try {
            $commit_obj = $repo->read_object($tip_hash);
            $commit = $commit_obj->as_commit();
        } catch (\Throwable $e) {
            throw new \RuntimeException("cannot read pushed commit $tip_hash: " . $e->getMessage());
        }

        $message = $commit->message ?? 'Push via git';
        $message = preg_replace('/\n\nDolt-Commit:.*$/s', '', $message);

        // Extract the tree
        $tree_hash = $commit->tree;
        $all_files = [];
        git_walk_tree($repo, $tree_hash, '', $all_files);

        // Separate wordpress/ files from the server-owned artifacts
        // (db.php drop-in, sqlite plugin tree, .ht.sqlite itself, meta).
        // The latter aren't persisted to branchfs — they're regenerated
        // from scratch on every clone, so echoing them through would
        // just bloat the overlay.
        $wp_files = [];
        $sqlite_blob_hash = null;
        $meta_blob_hash = null;
        foreach ($all_files as $path => $blob_hash) {
            if ($path === SQLITE_CLONE_META_FILE) {
                $meta_blob_hash = $blob_hash;
                continue;
            }
            // Push MUST include the canonical SQLite file.
            if ($path === 'wordpress/wp-content/database/.ht.sqlite') {
                $sqlite_blob_hash = $blob_hash;
                continue;
            }
            if (strncmp($path, 'wordpress/', 10) !== 0) continue;
            if (git_is_server_owned_path($path)) continue;
            $wp_files[substr($path, 10)] = $blob_hash;
        }

        if ($sqlite_blob_hash === null) {
            throw new \RuntimeException(
                "push rejected: missing wp-content/database/.ht.sqlite — " .
                "the cloned site must round-trip its SQLite database file"
            );
        }
        if ($meta_blob_hash !== null) {
            git_validate_db_meta($repo, $meta_blob_hash);
        }

        // Apply file changes to branchfs overlay (file tree only — DB state
        // is handled separately via the sqlite importer below).
        git_apply_file_changes($sqlite, $branch_id, $wp_files, $repo);

        // Spill the pushed .ht.sqlite to a temp file so the importer can
        // open it with SQLite3 (the class can't read from a PHP stream).
        $sqlite_tmp = tempnam(sys_get_temp_dir(), 'branchfs-push-');
        file_put_contents($sqlite_tmp, $repo->read_object($sqlite_blob_hash)->consume_all());
        try {
            sqlite_importer_apply($dolt, $dolt_db, $branch_name, $sqlite_tmp, $wp_tables, $table_pk);
        } finally {
            @unlink($sqlite_tmp);
        }

        // Dolt commit
        $esc_msg = $dolt->real_escape_string($message);
        $esc_author = $dolt->real_escape_string($auth_user . ' <' . $auth_user . '@branched-wp>');
        $dolt->query("CALL DOLT_ADD('-A')");
        git_drain($dolt);
        mysqli_report(MYSQLI_REPORT_OFF);
        $r = @$dolt->query("CALL DOLT_COMMIT('-m', '$esc_msg', '--author', '$esc_author')");
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        if ($r instanceof mysqli_result) $r->free();
        git_drain($dolt);

        // Record paired fs_commit
        $head = git_dolt_head_hash($dolt, $branch_name);
        if ($head !== '') {
            $existing = git_fs_find_commit_by_dolt_hash($sqlite, $branch_id, $head);
            if ($existing === null) {
                git_fs_record_snapshot($sqlite, $branch_id, $head, $message);
            }
        }
    }
}

/**
 * Walk a git tree recursively, collecting all blob paths and hashes.
 */
function git_walk_tree(GitRepository $repo, string $tree_hash, string $prefix, array &$result): void {
    $tree_obj = $repo->read_object($tree_hash);
    $tree = $tree_obj->as_tree();
    foreach ($tree->entries as $name => $entry) {
        $full_path = $prefix === '' ? $name : $prefix . '/' . $name;
        if ($entry->mode === '40000' || $entry->mode === TreeEntry::FILE_MODE_DIRECTORY) {
            git_walk_tree($repo, $entry->hash, $full_path, $result);
        } else {
            $result[$full_path] = $entry->hash;
        }
    }
}

/**
 * Apply file changes to the branchfs overlay by diffing against current state.
 */
function git_apply_file_changes(SQLite3 $sqlite, int $branch_id, array $new_files, GitRepository $repo): void {
    $current_tree = git_fs_resolve_tree($sqlite, $branch_id);

    // Files in new_files but not in current, or changed
    foreach ($new_files as $path => $blob_hash) {
        $new_content = $repo->read_object($blob_hash)->consume_all();
        $new_hash = hash('fnv1a64', $new_content);

        $current = $current_tree[$path] ?? null;
        if ($current !== null && $current['blob_hash'] === $new_hash) {
            continue; // unchanged
        }

        // Insert or update the blob
        $stmt = $sqlite->prepare("INSERT OR REPLACE INTO blobs (hash, data, size) VALUES (:h, :d, :s)");
        $stmt->bindValue(':h', $new_hash, SQLITE3_TEXT);
        $stmt->bindValue(':d', $new_content, SQLITE3_BLOB);
        $stmt->bindValue(':s', strlen($new_content), SQLITE3_INTEGER);
        $stmt->execute();

        // Insert or update the file entry
        $stmt = $sqlite->prepare("INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) VALUES (:b, :p, :h, :m, :t, 0)");
        $stmt->bindValue(':b', $branch_id, SQLITE3_INTEGER);
        $stmt->bindValue(':p', $path, SQLITE3_TEXT);
        $stmt->bindValue(':h', $new_hash, SQLITE3_TEXT);
        $stmt->bindValue(':m', 33188, SQLITE3_INTEGER);
        $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
        $stmt->execute();
    }

    // Files deleted (in current but not in new)
    foreach ($current_tree as $path => $entry) {
        if ($entry['is_dir']) continue;
        if (!isset($new_files[$path])) {
            // Tombstone
            $stmt = $sqlite->prepare("INSERT OR REPLACE INTO files (branch_id, path, blob_hash, mode, mtime, is_dir) VALUES (:b, :p, NULL, 0, :t, 0)");
            $stmt->bindValue(':b', $branch_id, SQLITE3_INTEGER);
            $stmt->bindValue(':p', $path, SQLITE3_TEXT);
            $stmt->bindValue(':t', time(), SQLITE3_INTEGER);
            $stmt->execute();
        }
    }
}

/**
 * Build the "DB artifacts" tree injected on every clone:
 *   wordpress/wp-content/database/.ht.sqlite      — the SQLite DB file
 *   wordpress/wp-content/db.php                   — drop-in that points
 *                                                    WP at the SQLite plugin
 *   wordpress/wp-content/plugins/sqlite-database-integration/*   — vendored plugin
 *   db-meta.json                                  — manifest for the push side
 */
function git_clone_db_artifacts(mysqli $dolt, string $branch, string $dolt_hash, array $wp_tables): array {
    $out = [];

    // 1. SQLite file. Cached across requests keyed by the Dolt commit
    // hash so info/refs and upload-pack see identical bytes even when
    // SQLite's own file format embeds non-deterministic page layouts
    // that would otherwise cause the advertised commit ref in
    // info/refs to differ from the one built during fetch.
    $sqlite_bytes = git_cached_sqlite_export($dolt, $branch, $dolt_hash, $wp_tables);
    $out['wordpress/wp-content/database/.ht.sqlite'] = $sqlite_bytes;

    // 2. The SQLite-integration drop-in. Upstream ships a db.copy template;
    // expand placeholders so the file is ready to go as wp-content/db.php.
    $db_copy = file_get_contents(__DIR__ . '/../../vendor/sqlite-database-integration/db.copy');
    // The drop-in path-search logic falls back to
    //   realpath(__DIR__ . '/plugins/sqlite-database-integration')
    // when the templated placeholder doesn't resolve, which is exactly
    // what we want. Replace the plugin placeholder with its canonical
    // slug so the admin activation path works too.
    $db_copy = str_replace('{SQLITE_PLUGIN}', 'sqlite-database-integration/load.php', $db_copy);
    $db_copy = str_replace('{SQLITE_IMPLEMENTATION_FOLDER_PATH}', '__PLUGIN_FOLDER_SENTINEL__', $db_copy);
    $out['wordpress/wp-content/db.php'] = $db_copy;

    // 3. Vendor the plugin into wp-content/plugins/.
    $plugin_src = realpath(__DIR__ . '/../../vendor/sqlite-database-integration');
    $plugin_dst_prefix = 'wordpress/wp-content/plugins/sqlite-database-integration/';
    git_clone_vendor_tree($plugin_src, $plugin_dst_prefix, $out);

    // 4. Manifest. `exported_at` anchors to the Dolt commit timestamp
    // instead of wall-clock now, so two requests for the same dolt_hash
    // produce byte-identical manifests — otherwise the git commit hash
    // would drift between info/refs and upload-pack within a single
    // clone, breaking fetch.
    $commit_ts = git_dolt_commit_timestamp($dolt, $dolt_hash);
    $out[SQLITE_CLONE_META_FILE] = json_encode([
        'dolt_commit_hash' => $dolt_hash,
        'branch'           => $branch,
        'exported_at'      => $commit_ts,
        'schema_version'   => SQLITE_CLONE_SCHEMA_VERSION,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

    return $out;
}

/**
 * Fetch the committer timestamp for a Dolt commit as an ISO-8601 string
 * (UTC). Falls back to a fixed epoch string if dolt_log doesn't know
 * the commit yet.
 */
function git_dolt_commit_timestamp(mysqli $dolt, string $dolt_hash): string {
    $esc = $dolt->real_escape_string($dolt_hash);
    $r = $dolt->query("SELECT date FROM dolt_log WHERE commit_hash = '$esc' LIMIT 1");
    $date = null;
    if ($r instanceof mysqli_result) {
        $row = $r->fetch_assoc();
        $r->free();
        $date = $row['date'] ?? null;
    }
    git_drain($dolt);
    if (!$date) return '1970-01-01T00:00:00+00:00';
    $ts = strtotime($date);
    if ($ts === false) return '1970-01-01T00:00:00+00:00';
    return gmdate('c', $ts);
}

/**
 * Cache wrapper around sqlite_exporter_build_file. Keyed by
 * (branch, dolt_hash) so the exact same bytes are served to info/refs
 * and the follow-up fetch within a clone. Cache lives under /tmp and
 * is cleaned out opportunistically.
 */
function git_cached_sqlite_export(mysqli $dolt, string $branch, string $dolt_hash, array $wp_tables): string {
    $cache_dir = sys_get_temp_dir() . '/branchfs-sqlite-cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0700, true);
    }
    $key  = hash('sha256', "$branch\x00$dolt_hash");
    $path = $cache_dir . '/' . $key . '.sqlite';

    if (is_file($path)) {
        @touch($path);
        return file_get_contents($path);
    }

    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.part';
    try {
        sqlite_exporter_build_file($dolt, $branch, $tmp, $wp_tables);
        // Atomic rename — concurrent requests may race here, last
        // writer wins but all writers produce the same bytes so it's
        // fine.
        @rename($tmp, $path);
    } finally {
        @unlink($tmp);
    }

    // Best-effort LRU trim: drop entries older than 1 hour.
    foreach (glob($cache_dir . '/*.sqlite') ?: [] as $f) {
        if (@filemtime($f) < time() - 3600) @unlink($f);
    }

    return file_get_contents($path);
}

/**
 * Recursively walk $src_dir and register every file under $dst_prefix
 * into the tree-updates array. Skips VCS + build noise.
 */
function git_clone_vendor_tree(string $src_dir, string $dst_prefix, array &$out): void {
    if (!is_dir($src_dir)) {
        throw new \RuntimeException("vendor dir not found: $src_dir");
    }
    $skip = ['.git', '.github', '.claude', '.devcontainer', 'tests', 'grammar-tools',
             'bin', 'composer.json', 'phpcs.xml.dist', '.editorconfig', '.gitattributes',
             '.gitignore', 'AGENTS.md', 'CLAUDE.md'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $path => $info) {
        $rel = substr($path, strlen($src_dir) + 1);
        $top = strtok($rel, '/');
        if (in_array($top, $skip, true)) continue;
        if ($info->isFile()) {
            $out[$dst_prefix . $rel] = file_get_contents($path);
        }
    }
}

/**
 * True if a pushed path belongs to the server-owned integration shim
 * that we regenerate on every clone. These files are not persisted to
 * branchfs; whatever the client pushes is discarded.
 */
function git_is_server_owned_path(string $path): bool {
    foreach (SQLITE_CLONE_SERVER_OWNED_PREFIXES as $prefix) {
        if (substr($prefix, -1) === '/') {
            if (strncmp($path, $prefix, strlen($prefix)) === 0) return true;
        } elseif ($path === $prefix) {
            return true;
        }
    }
    return false;
}

/**
 * Read the pushed db-meta.json blob and sanity-check its schema_version.
 * Mismatches are rejected with a clear error so clients don't silently
 * poke at an incompatible server shape.
 */
function git_validate_db_meta(GitRepository $repo, string $blob_hash): void {
    $raw = $repo->read_object($blob_hash)->consume_all();
    $meta = json_decode($raw, true);
    if (!is_array($meta)) {
        throw new \RuntimeException("push rejected: db-meta.json is not valid JSON");
    }
    $v = $meta['schema_version'] ?? null;
    if ((int)$v !== SQLITE_CLONE_SCHEMA_VERSION) {
        throw new \RuntimeException(
            "push rejected: db-meta.json schema_version=$v, server expects " . SQLITE_CLONE_SCHEMA_VERSION
        );
    }
}

/**
 * Get blob data from SQLite store.
 */
function git_get_blob_data(SQLite3 $sqlite, ?string $hash): ?string {
    if ($hash === null) return null;
    $stmt = $sqlite->prepare("SELECT data FROM blobs WHERE hash = :h");
    $stmt->bindValue(':h', $hash, SQLITE3_TEXT);
    $r = $stmt->execute();
    $row = $r->fetchArray(SQLITE3_NUM);
    return $row ? $row[0] : null;
}

function git_drain(mysqli $c): void {
    while ($c->next_result()) {
        $r = $c->store_result();
        if ($r instanceof mysqli_result) $r->free();
    }
}

function git_rmrf(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $f) {
        if ($f->isDir()) {
            @rmdir($f->getRealPath());
        } else {
            @unlink($f->getRealPath());
        }
    }
    @rmdir($dir);
}