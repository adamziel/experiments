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

use WordPress\Filesystem\LocalFilesystem;
use WordPress\Git\GitEndpoint;
use WordPress\Git\GitRepository;
use WordPress\Git\Model\Commit;
use WordPress\Git\Model\Tree;
use WordPress\Git\Model\TreeEntry;
use WordPress\Git\Protocol\GitProtocolEncoderPipe;
use WordPress\HttpServer\Response\StreamingResponseWriter;

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

    // Build or refresh the cached git repository
    $cache_dir = sys_get_temp_dir() . '/branchfs-git-cache';
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }

    $repo_dir = $cache_dir . '/repo';

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

    // After push: apply changes back to branchfs + Dolt
    if ($is_post_receive && $auth_user !== null) {
        try {
            git_process_push($repo_dir, $fs, $repo, $sqlite, $dolt, $dolt_db, $auth_user);
        } catch (\Throwable $e) {
            error_log("Push processing error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    $dolt->close();
}

function git_check_auth(): ?string {
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        return null;
    }
    // Accept any username/password for dev. In production this would validate.
    return $_SERVER['PHP_AUTH_USER'];
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

            // db/ files from Dolt
            $db_content = git_export_dolt_tables($dolt, $dolt_db, $branch_name, $wp_tables, $table_pk);
            foreach ($db_content as $filename => $content) {
                $updates['db/' . $filename] = $content;
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

            // Checkout this branch before committing
            $repo->checkout("refs/heads/$branch_name");
            if ($parent_git_hash !== null) {
                $repo->set_branch_tip("refs/heads/$branch_name", $parent_git_hash);
            }

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

    // Dolt commit first
    $esc = $dolt->real_escape_string($branch_name);
    $dolt->query("CALL DOLT_CHECKOUT('$esc')");
    git_drain($dolt);
    $dolt->query("CALL DOLT_ADD('-A')");
    git_drain($dolt);
    $r = $dolt->query("CALL DOLT_COMMIT('-am', 'Auto-snapshot for git')");
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
 */
function git_process_push(string $repo_dir, $fs, GitRepository $repo, SQLite3 $sqlite, mysqli $dolt, string $dolt_db, string $auth_user): void {
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

    // Find all branches and their tips
    $refs_dir = $repo_dir . '/refs/heads';
    if (!is_dir($refs_dir)) return;

    foreach (scandir($refs_dir) as $ref_file) {
        if ($ref_file[0] === '.') continue;
        $branch_name = $ref_file;
        $tip_hash = trim(file_get_contents($refs_dir . '/' . $ref_file));

        if (Commit::is_null_hash($tip_hash)) continue;

        // Ensure the branchfs branch exists
        $branch_id = git_fs_branch_id($sqlite, $branch_name);
        if ($branch_id === 0) {
            // New branch - create it
            if (extension_loaded('branchfs')) {
                $db_path_env = getenv('BRANCHFS_DB');
                if ($db_path_env) {
                    branchfs_set_db($db_path_env);
                    branchfs_create_branch($branch_name, 'main');
                }
            }
            // Also create Dolt branch
            $esc = $dolt->real_escape_string($branch_name);
            $dolt->query("CALL DOLT_BRANCH('$esc', 'main')");
            git_drain($dolt);

            $branch_id = git_fs_branch_id($sqlite, $branch_name);
            if ($branch_id === 0) continue;
        }

        // Read the pushed commit
        try {
            $commit_obj = $repo->read_object($tip_hash);
            $commit = $commit_obj->as_commit();
        } catch (\Throwable $e) {
            error_log("Cannot read pushed commit $tip_hash: " . $e->getMessage());
            continue;
        }

        $message = $commit->message ?? 'Push via git';
        // Strip Dolt-Commit trailer if present
        $message = preg_replace('/\n\nDolt-Commit:.*$/s', '', $message);

        // Extract the tree
        $tree_hash = $commit->tree;
        $all_files = [];
        git_walk_tree($repo, $tree_hash, '', $all_files);

        // Separate wordpress/ and db/ files
        $wp_files = [];
        $db_files = [];
        foreach ($all_files as $path => $blob_hash) {
            if (strncmp($path, 'wordpress/', 10) === 0) {
                $wp_files[substr($path, 10)] = $blob_hash;
            } elseif (strncmp($path, 'db/', 3) === 0) {
                $db_files[substr($path, 3)] = $blob_hash;
            }
        }

        // Apply file changes to branchfs overlay
        git_apply_file_changes($sqlite, $branch_id, $wp_files, $repo);

        // Apply DB changes from ndjson files
        $esc_branch = $dolt->real_escape_string($branch_name);
        $dolt->query("CALL DOLT_CHECKOUT('$esc_branch')");
        git_drain($dolt);

        foreach ($db_files as $filename => $blob_hash) {
            if (substr($filename, -7) !== '.ndjson') continue;
            $table_name = substr($filename, 0, -7); // strip .ndjson
            if (!in_array($table_name, $wp_tables)) continue;

            $new_content = $repo->read_object($blob_hash)->consume_all();
            git_apply_ndjson_changes($dolt, $dolt_db, $branch_name, $table_name, $new_content, $table_pk[$table_name] ?? null);
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
 * Apply NDJSON changes to a Dolt table.
 */
function git_apply_ndjson_changes(mysqli $dolt, string $dolt_db, string $branch, string $table, string $ndjson, ?string $pk): void {
    if (!$pk) return;

    $lines = explode("\n", trim($ndjson));
    $new_rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $row = json_decode($line, true);
        if ($row === null) continue;
        $new_rows[] = $row;
    }

    if (empty($new_rows)) return;

    $esc_table = $dolt->real_escape_string($table);

    // Use REPLACE INTO for each row (handles both inserts and updates)
    // First, delete rows not present in the new data
    $pk_cols = array_map('trim', explode(',', $pk));

    // Collect all PK values from the new data
    $new_pk_values = [];
    foreach ($new_rows as $row) {
        $pk_val = [];
        foreach ($pk_cols as $col) {
            $pk_val[] = $row[trim($col)] ?? '';
        }
        $new_pk_values[] = $pk_val;
    }

    // Delete rows not in the new data (for single PK column)
    if (count($pk_cols) === 1) {
        $pk_col = trim($pk_cols[0]);
        $esc_pk = $dolt->real_escape_string($pk_col);
        $pk_vals = array_map(function($row) use ($pk_col, $dolt) {
            return "'" . $dolt->real_escape_string((string)($row[$pk_col] ?? '')) . "'";
        }, $new_rows);
        if (!empty($pk_vals)) {
            $chunks = array_chunk($pk_vals, 500);
            foreach ($chunks as $chunk) {
                $in_list = implode(',', $chunk);
                $dolt->query("DELETE FROM `$esc_table` WHERE `$esc_pk` NOT IN ($in_list)");
                git_drain($dolt);
            }
        }
    } else {
        // For composite keys, truncate and re-insert
        $dolt->query("DELETE FROM `$esc_table`");
        git_drain($dolt);
    }

    // Insert/replace each row
    foreach ($new_rows as $row) {
        $cols = [];
        $vals = [];
        foreach ($row as $col => $val) {
            $cols[] = '`' . $dolt->real_escape_string($col) . '`';
            if ($val === null) {
                $vals[] = 'NULL';
            } elseif (is_int($val) || is_float($val)) {
                $vals[] = $val;
            } else {
                $vals[] = "'" . $dolt->real_escape_string((string)$val) . "'";
            }
        }
        $sql = "REPLACE INTO `$esc_table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        @$dolt->query($sql);
        git_drain($dolt);
    }
}

/**
 * Export Dolt tables as NDJSON + schema.
 */
function git_export_dolt_tables(mysqli $dolt, string $dolt_db, string $branch, array $tables, array $table_pk): array {
    $result = [];

    $esc = $dolt->real_escape_string($branch);
    $dolt->query("CALL DOLT_CHECKOUT('$esc')");
    git_drain($dolt);

    // schema.sql
    $schema = '';
    foreach ($tables as $table) {
        $r = $dolt->query("SHOW CREATE TABLE `$table`");
        if ($r instanceof mysqli_result) {
            $row = $r->fetch_assoc();
            if ($row) {
                $create = $row['Create Table'] ?? '';
                $schema .= $create . ";\n\n";
            }
            $r->free();
        }
        git_drain($dolt);
    }
    $result['schema.sql'] = $schema;

    // NDJSON exports
    foreach ($tables as $table) {
        $pk = $table_pk[$table] ?? 'id';
        $r = $dolt->query("SELECT * FROM `$table` ORDER BY $pk");
        if (!($r instanceof mysqli_result)) {
            $result[$table . '.ndjson'] = '';
            git_drain($dolt);
            continue;
        }

        $fields = $r->fetch_fields();
        $lines = [];
        while ($row = $r->fetch_assoc()) {
            // Detect and base64-encode binary fields
            foreach ($fields as $field) {
                $name = $field->name;
                if (isset($row[$name]) && ($field->type === MYSQLI_TYPE_BLOB || $field->type === MYSQLI_TYPE_LONG_BLOB || $field->type === MYSQLI_TYPE_MEDIUM_BLOB || $field->type === MYSQLI_TYPE_TINY_BLOB)) {
                    if ($row[$name] !== '' && !mb_check_encoding($row[$name], 'UTF-8')) {
                        $row[$name] = base64_encode($row[$name]);
                    }
                }
            }
            $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $r->free();
        git_drain($dolt);
        $result[$table . '.ndjson'] = implode("\n", $lines) . ($lines ? "\n" : '');
    }

    return $result;
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