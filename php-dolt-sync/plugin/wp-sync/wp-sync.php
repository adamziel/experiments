<?php
/**
 * Plugin Name: WP Sync
 * Description: Content-addressed DB sync between two WordPress + SQLite sites. Dolt-style DAG of commits, push/pull over the REST API, materialize on the other side.
 * Version:     0.1.0
 * Requires PHP: 8.2
 * License:     MIT
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/src/autoload.php';

WpSync\WordPress\SyncPlugin::bootstrap();

if (defined('WP_CLI') && WP_CLI) {
    $cli = new WpSync\WordPress\WpCliCommands();
    WP_CLI::add_command('sync commit', [$cli, 'commit']);
    WP_CLI::add_command('sync push',   [$cli, 'push']);
    WP_CLI::add_command('sync pull',   [$cli, 'pull']);
    WP_CLI::add_command('sync status', [$cli, 'status']);
    WP_CLI::add_command('sync log',    [$cli, 'log_']);
}
