<?php
/**
 * Install WordPress against the integration-test MariaDB.
 *
 * - Downloads WP core into /tmp/wp-src-cache/ if missing
 * - Creates database `wptest`
 * - Runs wp_install() to create schema + default rows
 * - Seeds 5,000 posts, 50 users, ~20k postmeta, ~500 options (some autoload)
 *
 * Idempotent: if the DB already has a 'siteurl' option and >= 5000 posts it exits early.
 */

$harnessDir = __DIR__;
$envFile    = $harnessDir . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "ERROR: harness/.env not found. Run start-mariadb.sh first.\n");
    exit(1);
}

// Load env
$env = parse_ini_file($envFile);
$host = $env['HOST'] ?? '127.0.0.1';
$port = (int) ($env['PORT'] ?? 0);
$user = $env['USER'] ?? 'root';
$pass = $env['PASSWORD'] ?? '';
$db   = $env['DBNAME'] ?? 'wptest';

$wpVersion  = '6.5.5';
$wpCacheDir = '/tmp/wp-src-cache';
$wpSrcDir   = $wpCacheDir . "/wordpress-{$wpVersion}";

// -----------------------------------------------------------------------------
// Step 1: Create the DB and bail if already installed and seeded.
// -----------------------------------------------------------------------------
$rootPdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Fast path: is WP already installed + seeded?
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $siteurl = $pdo->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'")->fetchColumn();
    $postCount = (int) $pdo->query("SELECT COUNT(*) FROM wp_posts")->fetchColumn();
    if ($siteurl && $postCount >= 5000) {
        echo "WordPress already installed and seeded (posts={$postCount}). Skipping.\n";
        exit(0);
    }
} catch (Exception $e) {
    // Proceed with install.
}

// Wipe and recreate to get a clean, deterministic seed state.
$rootPdo->exec("DROP DATABASE IF EXISTS `{$db}`");
$rootPdo->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// -----------------------------------------------------------------------------
// Step 2: Download WP core if not cached.
// -----------------------------------------------------------------------------
if (!is_dir($wpSrcDir) || !file_exists($wpSrcDir . '/wp-settings.php')) {
    echo "Downloading WordPress {$wpVersion}...\n";
    @mkdir($wpCacheDir, 0777, true);
    $zipPath = $wpCacheDir . "/wordpress-{$wpVersion}.tar.gz";
    if (!file_exists($zipPath)) {
        $url = "https://wordpress.org/wordpress-{$wpVersion}.tar.gz";
        $ctx = stream_context_create(['http' => ['timeout' => 120]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            fwrite(STDERR, "ERROR: failed to download {$url}\n");
            exit(1);
        }
        file_put_contents($zipPath, $data);
    }
    // Extract.
    $tmpExtract = $wpCacheDir . '/extract-tmp';
    @mkdir($tmpExtract, 0777, true);
    passthru("tar -xzf " . escapeshellarg($zipPath) . " -C " . escapeshellarg($tmpExtract), $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "ERROR: tar extract failed\n");
        exit(1);
    }
    rename($tmpExtract . '/wordpress', $wpSrcDir);
    passthru("rm -rf " . escapeshellarg($tmpExtract));
}

// -----------------------------------------------------------------------------
// Step 3: Write a wp-config.php pointing at the test MariaDB.
// -----------------------------------------------------------------------------
$wpConfigPath = $wpSrcDir . '/wp-config.php';
$saltBlock = '';
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $k) {
    $saltBlock .= "define('{$k}', '" . bin2hex(random_bytes(16)) . "');\n";
}
$wpConfig = <<<PHP
<?php
define('DB_NAME', '{$db}');
define('DB_USER', '{$user}');
define('DB_PASSWORD', '{$pass}');
define('DB_HOST', '{$host}:{$port}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
\$table_prefix = 'wp_';
{$saltBlock}
define('WP_DEBUG', false);
define('ABSPATH', __DIR__ . '/');
define('WP_HOME', 'http://cow-integration-test.local');
define('WP_SITEURL', 'http://cow-integration-test.local');
if (!defined('WPINC')) define('WPINC', 'wp-includes');
// Keep WP from emitting any HTTP headers during CLI install.
if (!defined('WP_CLI')) define('WP_CLI', true);
require_once ABSPATH . 'wp-settings.php';
PHP;
file_put_contents($wpConfigPath, $wpConfig);

// -----------------------------------------------------------------------------
// Step 4: Run wp_install() and then seed data.
// -----------------------------------------------------------------------------
echo "Running wp_install() via WP core...\n";

// Child process invocation so any of WP's output quirks don't pollute ours.
$seederPath = $harnessDir . '/_wp-install-runner.php';
file_put_contents($seederPath, <<<'PHP'
<?php
// Runs inside WordPress context.
$_SERVER['HTTP_HOST']   = 'cow-integration-test.local';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'cow-integration-test.local';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$wpSrcDir = $argv[1];
define('WP_INSTALLING', true);
define('ABSPATH_OVERRIDE', $wpSrcDir . '/');

require_once $wpSrcDir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

$result = wp_install(
    'COW Integration Test Site',
    'admin',
    'admin@example.com',
    true,                 // public
    '',
    'password',
    'en_US'
);

if (is_wp_error($result)) {
    fwrite(STDERR, "wp_install failed: " . $result->get_error_message() . "\n");
    exit(1);
}

// --- Seed data ---
global $wpdb;

$target_posts = 5000;
$target_users = 50;

// Existing counts (wp_install creates 1 post/page/comment + 1 user).
$existing_users = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
$existing_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'");

echo "Seeding users (target {$target_users})...\n";
$wpdb->query('START TRANSACTION');
for ($i = $existing_users; $i < $target_users; $i++) {
    $login = 'user' . $i;
    $hash  = '$P$Bdummyhashforintegrationtesting1234567';
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->users} (user_login, user_pass, user_nicename, user_email, user_registered, user_status, display_name)
         VALUES (%s, %s, %s, %s, NOW(), 0, %s)",
        $login, $hash, $login, "{$login}@example.com", $login
    ));
}
$wpdb->query('COMMIT');

echo "Seeding posts (target {$target_posts})...\n";
$wpdb->query('SET autocommit=0');
$wpdb->query('START TRANSACTION');
$batch = 500;
$needed = $target_posts - $existing_posts;
for ($i = 0; $i < $needed; $i++) {
    $title   = "Seeded Post #" . ($i + 1);
    $slug    = 'seeded-post-' . ($i + 1);
    $content = str_repeat("Lorem ipsum dolor sit amet. ", 8);
    $authorId = 1 + ($i % max(1, $target_users));
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->posts}
          (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
           post_status, comment_status, ping_status, post_password, post_name,
           to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered,
           post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
         VALUES (%d, NOW(), NOW(), %s, %s, '',
                 'publish', 'open', 'open', '', %s,
                 '', '', NOW(), NOW(), '',
                 0, %s, 0, 'post', '', 0)",
        $authorId, $content, $title, $slug,
        "http://cow-integration-test.local/?p=seed" . ($i + 1)
    ));
    if (($i + 1) % $batch === 0) {
        $wpdb->query('COMMIT');
        $wpdb->query('START TRANSACTION');
    }
}
$wpdb->query('COMMIT');

echo "Seeding postmeta (~20000)...\n";
// Use a fresh set of meta for 4000 of the posts (5 meta rows each = 20k).
$postIds = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY ID LIMIT 4000");
$wpdb->query('START TRANSACTION');
$i = 0;
foreach ($postIds as $pid) {
    for ($k = 0; $k < 5; $k++) {
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
            $pid, "_seed_meta_{$k}", "value-{$pid}-{$k}"
        ));
    }
    if (++$i % 1000 === 0) {
        $wpdb->query('COMMIT');
        $wpdb->query('START TRANSACTION');
    }
}
$wpdb->query('COMMIT');

echo "Seeding options (~500)...\n";
$wpdb->query('START TRANSACTION');
for ($i = 0; $i < 500; $i++) {
    $name  = "seed_option_{$i}";
    $val   = "option-value-{$i}";
    // ~30% autoload yes.
    $auto  = ($i % 3 === 0) ? 'yes' : 'no';
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)
         ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",
        $name, $val, $auto
    ));
}
$wpdb->query('COMMIT');
$wpdb->query('SET autocommit=1');

$posts   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
$users   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
$meta    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
$options = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}");
echo "Seeded: posts={$posts} users={$users} postmeta={$meta} options={$options}\n";
PHP);

passthru('php -d display_errors=1 -d error_reporting=E_ERROR ' .
    escapeshellarg($seederPath) . ' ' . escapeshellarg($wpSrcDir), $rc);
if ($rc !== 0) {
    fwrite(STDERR, "WordPress install/seed failed (rc={$rc}).\n");
    exit(1);
}

echo "WordPress install complete.\n";
