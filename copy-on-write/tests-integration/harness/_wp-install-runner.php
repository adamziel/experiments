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