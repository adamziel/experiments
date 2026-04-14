<?php
declare(strict_types=1);

namespace WpSync\WordPress;

use WpSync\Encoding\CanonicalEncoder;
use WpSync\Snapshot\Materializer;
use WpSync\Snapshot\Snapshotter;
use WpSync\Snapshot\RowNormalizer;
use WpSync\Snapshot\SchemaInspector;
use WpSync\Store\HttpChunkStore;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Store\SQLiteRefStore;
use WpSync\Sync\FetchCommand;
use WpSync\Sync\PushCommand;
use WpSync\Sync\SyncEngine;

/**
 * WP-CLI commands. Registered with `WP_CLI::add_command('sync', WpCliCommands::class)`.
 */
final class WpCliCommands
{
    /** sync commit --message=<msg> [--author=<name>] */
    public function commit(array $args, array $assoc): void
    {
        $db = $this->openWpDb();
        $cs = new SQLiteChunkStore(SyncPlugin::chunkDbPath());
        $rs = SQLiteRefStore::open(SyncPlugin::chunkDbPath());
        $tables = SchemaInspector::listTables($db);
        $siteUrl = $this->siteUrl($db);

        $filters = [];
        if (in_array('wp_options', $tables, true)) {
            $filters['wp_options'] = static function (array $row) {
                $name = (string)($row['option_name'] ?? '');
                if (RowNormalizer::isExcludedOption($name)) return null;
                return $row;
            };
        }

        $snap = new Snapshotter($db, $cs, $siteUrl);
        $parent = $rs->getRef('refs/heads/main');
        $priorRoot = null;
        $parents = [];
        if ($parent !== null) {
            $parents = [$parent];
            $commit = Snapshotter::loadCommit($parent, $cs);
            $priorRoot = $commit['root'];
        }
        $hash = $snap->commit(
            tables: $tables,
            author: (string)($assoc['author'] ?? 'wp-sync'),
            message: (string)($assoc['message'] ?? ''),
            parents: $parents,
            priorRootHash: $priorRoot,
            rowFilters: $filters,
        );
        $ok = $rs->casRef('refs/heads/main', $parent, $hash);
        if (!$ok) throw new \RuntimeException('ref update failed');
        $this->log("committed: {$hash}");
    }

    /** sync push --remote=<url> --user=<user> --password=<app-password> */
    public function push(array $args, array $assoc): void
    {
        $cs = new SQLiteChunkStore(SyncPlugin::chunkDbPath());
        $rs = SQLiteRefStore::open(SyncPlugin::chunkDbPath());
        $http = new HttpChunkStore((string)$assoc['remote'], (string)$assoc['user'], (string)$assoc['password']);
        $engine = new SyncEngine();
        $push = new PushCommand($engine, $cs, $rs, $http);
        $r = $push->run();
        $this->log("push: {$r['status']} transferred={$r['transferred']}");
    }

    /** sync pull --remote=<url> --user=<user> --password=<app-password> */
    public function pull(array $args, array $assoc): void
    {
        $db = $this->openWpDb();
        $cs = new SQLiteChunkStore(SyncPlugin::chunkDbPath());
        $rs = SQLiteRefStore::open(SyncPlugin::chunkDbPath());
        $http = new HttpChunkStore((string)$assoc['remote'], (string)$assoc['user'], (string)$assoc['password']);
        $engine = new SyncEngine();
        $fetch = new FetchCommand($engine, $cs, $rs, $http);
        $r = $fetch->run();
        if ($r['hash'] === null) {
            $this->log('pull: empty remote'); return;
        }
        $mat = new Materializer($db, $cs, $this->siteUrl($db));
        $filters = [
            'wp_options' => fn($row) => RowNormalizer::isExcludedOption((string)($row['option_name'] ?? '')) ? null : $row,
        ];
        $mat->materialize($r['hash'], null, $filters);
        foreach ($mat->warnings as $w) $this->log("warn: {$w}");
        // Fast-forward local main to fetched commit.
        $localMain = $rs->getRef('refs/heads/main');
        $rs->casRef('refs/heads/main', $localMain, $r['hash']);
        $this->log("pulled: {$r['hash']}");
    }

    /** sync status */
    public function status(array $args, array $assoc): void
    {
        $rs = SQLiteRefStore::open(SyncPlugin::chunkDbPath());
        foreach ($rs->listRefs() as $n => $h) {
            $this->log("{$n} {$h}");
        }
    }

    /** sync log [--max=<n>] */
    public function log_(array $args, array $assoc): void
    {
        $cs = new SQLiteChunkStore(SyncPlugin::chunkDbPath());
        $rs = SQLiteRefStore::open(SyncPlugin::chunkDbPath());
        $h = $rs->getRef('refs/heads/main');
        $max = (int)($assoc['max'] ?? 20);
        while ($h && $max-- > 0) {
            $c = Snapshotter::loadCommit($h, $cs);
            $ts = date('c', (int)$c['timestamp']);
            $this->log("{$h} {$ts} {$c['author']}: {$c['message']}");
            $h = $c['parents'][0] ?? null;
        }
    }

    private function openWpDb(): \SQLite3
    {
        $path = SyncPlugin::wpDbPath();
        $db = new \SQLite3($path, SQLITE3_OPEN_READWRITE);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=WAL');
        return $db;
    }

    private function siteUrl(\SQLite3 $db): string
    {
        $res = $db->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'");
        if ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
            return (string)$row['option_value'];
        }
        return 'http://localhost';
    }

    private function log(string $msg): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($msg);
        } else {
            fwrite(STDOUT, $msg . "\n");
        }
    }
}
