<?php
/**
 * Bootstrap WordPress in BranchFS store with Dolt backend.
 *
 * Writes wp-config.php and mu-plugin into the store,
 * then installs WordPress via wp_install().
 *
 * Usage: php bootstrap_wp.php <db-path> <wp-root> <dolt-port> <site-title> <mu-plugin-path> <debug-log>
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

$db_path      = $argv[1] ?? die("Usage: php bootstrap_wp.php <db-path> <wp-root> <dolt-port> <site-title> <mu-plugin> <debug-log>\n");
$wp_root      = rtrim($argv[2], '/');
$dolt_port    = $argv[3];
$site_title   = $argv[4] ?? 'Branched WP';
$mu_plugin    = $argv[5] ?? null;
$debug_log    = $argv[6] ?? '/tmp/wp-debug.log';

// --- Init branchfs (protocol only, no interception yet) ---
branchfs_set_db($db_path);
branchfs_set_root($wp_root);

// --- Verify Dolt connection ---
$test = @new mysqli('127.0.0.1', 'root', '', 'wordpress', (int)$dolt_port);
if ($test->connect_error) {
    die("ERROR: Cannot connect to Dolt on port $dolt_port: {$test->connect_error}\n");
}
$test->close();
echo "  Dolt connection verified\n";

// --- Write wp-config.php ---
// Uses branch-qualified DB_NAME (wordpress/branch) so Dolt branch switching
// happens at connection time, before WordPress loads any options.
$config = <<<'CFG'
<?php
$_branchfs_branch = function_exists('branchfs_get_branch') ? branchfs_get_branch() : 'main';
if ($_branchfs_branch && $_branchfs_branch !== 'main') {
    define('DB_NAME', 'wordpress/' . $_branchfs_branch);
} else {
    define('DB_NAME', 'wordpress');
}

define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1:__DOLT_PORT__');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('AUTH_KEY',         'e2e-k1-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SECURE_AUTH_KEY',  'e2e-k2-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('LOGGED_IN_KEY',    'e2e-k3-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('NONCE_KEY',        'e2e-k4-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('AUTH_SALT',        'e2e-s1-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('SECURE_AUTH_SALT', 'e2e-s2-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('LOGGED_IN_SALT',   'e2e-s3-xxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('NONCE_SALT',       'e2e-s4-xxxxxxxxxxxxxxxxxxxxxxxxxxx');

$table_prefix = 'wp_';

define('BRANCHFS_DOLT_ENABLED', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '__DEBUG_LOG__');
define('WP_DEBUG_DISPLAY', false);
define('DISALLOW_FILE_MODS', true);
define('WP_AUTO_UPDATE_CORE', false);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);

if (isset($_SERVER['HTTP_HOST'])) {
    define('WP_HOME', 'http://' . $_SERVER['HTTP_HOST']);
    define('WP_SITEURL', 'http://' . $_SERVER['HTTP_HOST']);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
CFG;

$config = str_replace('__DOLT_PORT__', $dolt_port, $config);
$config = str_replace('__DEBUG_LOG__', $debug_log, $config);

$written = file_put_contents("branchfs://main/wp-config.php", $config);
if ($written === false) die("ERROR: Could not write wp-config.php\n");
echo "  wp-config.php written ($written bytes)\n";

// --- Write mu-plugin ---
if ($mu_plugin && file_exists($mu_plugin)) {
    @mkdir("branchfs://main/wp-content/mu-plugins", 0755, true);
    file_put_contents(
        "branchfs://main/wp-content/mu-plugins/branchfs-wp.php",
        file_get_contents($mu_plugin)
    );
    echo "  mu-plugin installed\n";
}

// --- Install WordPress ---
branchfs_set_branch('main');
branchfs_activate();
chdir($wp_root);

$_SERVER = array_merge($_SERVER ?? [], [
    'HTTP_HOST'       => '127.0.0.1',
    'REQUEST_URI'     => '/',
    'REQUEST_METHOD'  => 'GET',
    'SERVER_NAME'     => '127.0.0.1',
    'SERVER_PORT'     => '80',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'DOCUMENT_ROOT'   => $wp_root,
    'SCRIPT_FILENAME' => $wp_root . '/index.php',
]);

define('WP_INSTALLING', true);

ob_start();
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$result = wp_install($site_title, 'admin', 'test@test.com', false, '', 'admin');
ob_end_clean();

if (!empty($result['user_id']) && $result['user_id'] > 0) {
    echo "  WordPress installed (admin user_id={$result['user_id']})\n";
} else {
    echo "  WARNING: wp_install result: " . print_r($result, true) . "\n";
}

// --- Commit initial state to Dolt ---
global $wpdb;
$wpdb->query("CALL DOLT_ADD('-A')");
while ($wpdb->dbh->next_result()) { $r = $wpdb->dbh->store_result(); if ($r) $r->free(); }
$wpdb->query("CALL DOLT_COMMIT('-am', 'Initial WordPress install')");
while ($wpdb->dbh->next_result()) { $r = $wpdb->dbh->store_result(); if ($r) $r->free(); }
echo "  Dolt initial commit created\n";

// --- Pair a branchfs fs_commit so reset/rollback has something to land on ---
$r = $wpdb->dbh->query("SELECT hash FROM dolt_branches WHERE name = 'main' LIMIT 1");
$hrow = $r ? $r->fetch_assoc() : null;
if ($r) $r->free();
while ($wpdb->dbh->next_result()) { $r = $wpdb->dbh->store_result(); if ($r) $r->free(); }
if (!empty($hrow['hash'])) {
    $sd = new SQLite3($db_path, SQLITE3_OPEN_READWRITE);
    $sd->exec("CREATE TABLE IF NOT EXISTS fs_commits (id INTEGER PRIMARY KEY AUTOINCREMENT, branch_id INTEGER NOT NULL, dolt_hash TEXT NOT NULL, parent_id INTEGER, message TEXT, created_at TEXT DEFAULT (datetime('now')), UNIQUE (branch_id, dolt_hash))");
    $sd->exec("CREATE INDEX IF NOT EXISTS idx_fs_commits_branch ON fs_commits(branch_id)");
    $sd->exec("CREATE INDEX IF NOT EXISTS idx_fs_commits_dolt ON fs_commits(branch_id, dolt_hash)");
    $sd->exec("CREATE TABLE IF NOT EXISTS fs_commit_files (commit_id INTEGER NOT NULL, path TEXT NOT NULL, blob_hash TEXT, mode INTEGER, mtime INTEGER, is_dir INTEGER DEFAULT 0, PRIMARY KEY (commit_id, path))");
    $bid = (int)$sd->querySingle("SELECT id FROM branches WHERE name = 'main'");
    if ($bid) {
        $existing = (int)$sd->querySingle("SELECT id FROM fs_commits WHERE branch_id = $bid AND dolt_hash = '" . $sd->escapeString($hrow['hash']) . "'");
        if (!$existing) {
            $sd->exec("INSERT INTO fs_commits (branch_id, dolt_hash, message) VALUES ($bid, '" . $sd->escapeString($hrow['hash']) . "', 'Initial WordPress install')");
            $cid = $sd->lastInsertRowID();
            $sd->exec('BEGIN');
            $sd->exec("INSERT INTO fs_commit_files (commit_id, path, blob_hash, mode, mtime, is_dir) "
                . "SELECT $cid, path, blob_hash, mode, mtime, is_dir FROM files WHERE branch_id = $bid");
            $sd->exec('COMMIT');
            $n = (int)$sd->querySingle("SELECT COUNT(*) FROM fs_commit_files WHERE commit_id = $cid");
            echo "  branchfs fs_commit #$cid recorded ($n files) paired with dolt " . substr($hrow['hash'], 0, 12) . "\n";
        }
    }
    $sd->close();
}
