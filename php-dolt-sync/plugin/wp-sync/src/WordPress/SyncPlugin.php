<?php
declare(strict_types=1);

namespace WpSync\WordPress;

use WpSync\Store\Chunk;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Store\SQLiteRefStore;
use WpSync\Snapshot\DirtyTracker;
use WpSync\Snapshot\SchemaInspector;

/**
 * WordPress mu-plugin: exposes /wp-json/sync/v1/* REST endpoints and
 * installs dirty-tracking triggers on activation.
 *
 * Intended to be loaded as: wp-content/mu-plugins/sync.php, which
 * requires this class.
 */
final class SyncPlugin
{
    /** Path to the content-addressed chunks/refs database (separate from WP DB). */
    public static function chunkDbPath(): string
    {
        $path = defined('WPSYNC_CHUNK_DB') ? (string)WPSYNC_CHUNK_DB
                                           : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/wpsync-chunks.sqlite' : sys_get_temp_dir() . '/wpsync-chunks.sqlite');
        return $path;
    }

    /** Path to the WP SQLite DB (from the sqlite-database-integration plugin). */
    public static function wpDbPath(): string
    {
        if (defined('WPSYNC_WP_DB')) return (string)WPSYNC_WP_DB;
        $default = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/database/.ht.sqlite' : '';
        return $default;
    }

    public static function bootstrap(): void
    {
        if (!function_exists('add_action')) return;
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        $perm = static function (\WP_REST_Request $req) {
            return current_user_can('manage_options') ? true : new \WP_Error('forbidden', 'forbidden', ['status' => 403]);
        };

        register_rest_route('sync/v1', '/chunks/has', [
            'methods' => 'POST',
            'permission_callback' => $perm,
            'callback' => [self::class, 'routeHas'],
        ]);
        register_rest_route('sync/v1', '/chunks/get', [
            'methods' => 'POST',
            'permission_callback' => $perm,
            'callback' => [self::class, 'routeGet'],
        ]);
        register_rest_route('sync/v1', '/chunks/put', [
            'methods' => 'POST',
            'permission_callback' => $perm,
            'callback' => [self::class, 'routePut'],
        ]);
        register_rest_route('sync/v1', '/refs', [
            'methods' => 'GET',
            'permission_callback' => $perm,
            'callback' => [self::class, 'routeListRefs'],
        ]);
        register_rest_route('sync/v1', '/refs/(?P<name>.+)', [
            'methods' => 'PUT',
            'permission_callback' => $perm,
            'callback' => [self::class, 'routeCasRef'],
        ]);
    }

    public static function routeHas(\WP_REST_Request $req): \WP_REST_Response
    {
        $hashes = $req->get_param('hashes') ?? [];
        $cs = new SQLiteChunkStore(self::chunkDbPath());
        return new \WP_REST_Response(['present' => $cs->hasMany($hashes)]);
    }

    public static function routeGet(\WP_REST_Request $req): \WP_REST_Response
    {
        $hashes = $req->get_param('hashes') ?? [];
        $cs = new SQLiteChunkStore(self::chunkDbPath());
        $out = [];
        foreach ($cs->getMany($hashes) as $c) {
            $out[] = ['hash' => $c->hash, 'data' => base64_encode($c->bytes), 'refs' => $c->refs];
        }
        return new \WP_REST_Response(['chunks' => $out]);
    }

    public static function routePut(\WP_REST_Request $req): \WP_REST_Response
    {
        $chunks = $req->get_param('chunks') ?? [];
        $cs = new SQLiteChunkStore(self::chunkDbPath());
        $objs = [];
        foreach ($chunks as $c) {
            $raw = base64_decode($c['data'], true);
            if ($raw === false) {
                return new \WP_REST_Response(['error' => 'bad base64'], 400);
            }
            $objs[] = new Chunk($c['hash'], $raw, $c['refs'] ?? []);
        }
        try {
            $cs->putMany($objs);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['error' => $e->getMessage()], 400);
        }
        return new \WP_REST_Response(['stored' => count($objs)]);
    }

    public static function routeListRefs(\WP_REST_Request $req): \WP_REST_Response
    {
        $rs = SQLiteRefStore::open(self::chunkDbPath());
        return new \WP_REST_Response($rs->listRefs());
    }

    public static function routeCasRef(\WP_REST_Request $req): \WP_REST_Response
    {
        $name = (string)$req['name'];
        $old = $req->get_param('old');
        $new = (string)$req->get_param('new');
        $rs = SQLiteRefStore::open(self::chunkDbPath());
        $ok = $rs->casRef($name, $old === null || $old === '' ? null : (string)$old, $new);
        if (!$ok) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'cas_mismatch'], 409);
        }
        return new \WP_REST_Response(['ok' => true]);
    }

    /** Install dirty-tracking triggers on all WP tables. */
    public static function installTriggers(\SQLite3 $db): void
    {
        $tables = SchemaInspector::listTables($db);
        DirtyTracker::install($db, $tables);
    }
}
