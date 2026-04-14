<?php
// wp-config for wp-sync demo. SQLite via the sqlite-database-integration plugin's db.php drop-in.

$site_url = getenv('WP_SITEURL') ?: 'http://localhost:8081';
define('WP_HOME',    $site_url);
define('WP_SITEURL', $site_url);

$table_prefix = 'wp_';

// Keys/salts. Demo only — regenerate for anything real.
define('AUTH_KEY',         'demo-auth-key-change-me');
define('SECURE_AUTH_KEY',  'demo-secure-auth-key-change-me');
define('LOGGED_IN_KEY',    'demo-logged-in-key-change-me');
define('NONCE_KEY',        'demo-nonce-key-change-me');
define('AUTH_SALT',         'demo-auth-salt-change-me');
define('SECURE_AUTH_SALT',  'demo-secure-auth-salt-change-me');
define('LOGGED_IN_SALT',    'demo-logged-in-salt-change-me');
define('NONCE_SALT',        'demo-nonce-salt-change-me');

define('WP_DEBUG', false);
define('DISALLOW_FILE_MODS', false);

// Where wp-sync stores its content-addressed chunk+ref DB. Kept outside the WP SQLite DB on purpose.
define('WPSYNC_CHUNK_DB', '/var/www/html/wpsync-data/chunks.sqlite');
define('WPSYNC_WP_DB',    '/var/www/html/wp-content/database/.ht.sqlite');

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
