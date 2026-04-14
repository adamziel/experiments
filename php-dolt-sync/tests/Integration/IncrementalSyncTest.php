<?php
declare(strict_types=1);

use WpSync\Snapshot\Snapshotter;
use WpSync\Snapshot\SchemaInspector;
use WpSync\Snapshot\RowNormalizer;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Store\SQLiteRefStore;
use WpSync\Store\HttpChunkStore;
use WpSync\Sync\SyncEngine;
use WpSync\Sync\PushCommand;

require_once __DIR__ . '/../harness/WpFixture.php';
require_once __DIR__ . '/../harness/InProcessTransport.php';

TestRun::group('IncrementalSync', function () {
    TestRun::it('changing one row in a large table transfers << n chunks', function () {
        $src = \WpSync\Test\Harness\WpFixture::createDb('https://site-a.test');
        $src->exec('BEGIN');
        for ($i = 0; $i < 2000; $i++) {
            \WpSync\Test\Harness\WpFixture::insertPost($src, "Post {$i}", "content {$i}");
        }
        $src->exec('COMMIT');

        $pA = tempnam(sys_get_temp_dir(), 'a_'); unlink($pA);
        $csA = new SQLiteChunkStore($pA);
        $rsA = SQLiteRefStore::open($pA);
        $pR = tempnam(sys_get_temp_dir(), 'r_'); unlink($pR);
        $csR = new SQLiteChunkStore($pR);
        $rsR = SQLiteRefStore::open($pR);
        $xport = new \WpSync\Test\Harness\InProcessTransport($csR, $rsR);
        $http = new HttpChunkStore('http://x', 'u', 'p', $xport->asCallable());

        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $snap = new Snapshotter($src, $csA, 'https://site-a.test');
        $c1 = $snap->commit(SchemaInspector::listTables($src), 'a', 'first', [], null, $filters);
        $rsA->casRef('refs/heads/main', null, $c1);
        (new PushCommand(new SyncEngine(), $csA, $rsA, $http))->run();

        // Change one row.
        $src->exec("UPDATE wp_posts SET post_title='CHANGED' WHERE ID=1000");

        $before = $xport->putCount;
        $c2 = $snap->commit(SchemaInspector::listTables($src), 'a', 'second', [$c1], null, $filters);
        $rsA->casRef('refs/heads/main', $c1, $c2);
        (new PushCommand(new SyncEngine(), $csA, $rsA, $http))->run();
        $transferred = $xport->putCount - $before;

        // 2000 posts / 64 fanout = 32 leaves -> ~1 internal level.
        // Incremental push should transfer O(log n) chunks, not O(n).
        assertTrue($transferred < 50, "incremental push transferred {$transferred} chunks, expected << 2000");
    });
});
