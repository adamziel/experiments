<?php
declare(strict_types=1);

use WpSync\Snapshot\Snapshotter;
use WpSync\Snapshot\Materializer;
use WpSync\Snapshot\SchemaInspector;
use WpSync\Snapshot\RowNormalizer;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Store\SQLiteRefStore;
use WpSync\Store\HttpChunkStore;
use WpSync\Sync\SyncEngine;
use WpSync\Sync\PushCommand;
use WpSync\Sync\FetchCommand;

require_once __DIR__ . '/../harness/WpFixture.php';
require_once __DIR__ . '/../harness/InProcessTransport.php';

TestRun::group('SyncBetweenStores (HTTP-over-in-process)', function () {
    TestRun::it('push from A to B via HTTP transport, then materialize on B', function () {
        $srcDb = \WpSync\Test\Harness\WpFixture::createDb('https://site-a.test');
        \WpSync\Test\Harness\WpFixture::insertPost($srcDb, 'Post 1', '<a href="https://site-a.test/x">link</a>');
        \WpSync\Test\Harness\WpFixture::insertPost($srcDb, 'Post 2', 'content');

        // Local (site A) chunk store + ref store
        $pA = tempnam(sys_get_temp_dir(), 'cs_A_'); unlink($pA);
        $csA = new SQLiteChunkStore($pA);
        $rsA = SQLiteRefStore::open($pA);

        // Remote (site B) chunk store + ref store
        $pB = tempnam(sys_get_temp_dir(), 'cs_B_'); unlink($pB);
        $csB = new SQLiteChunkStore($pB);
        $rsB = SQLiteRefStore::open($pB);

        // Snapshot site A -> commit to local A chunk store.
        $snap = new Snapshotter($srcDb, $csA, 'https://site-a.test');
        $tables = SchemaInspector::listTables($srcDb);
        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];
        $commit = $snap->commit($tables, 'a', 'first', [], null, $filters);
        $rsA->casRef('refs/heads/main', null, $commit);

        // HTTP transport routes directly to B's stores.
        $xport = new \WpSync\Test\Harness\InProcessTransport($csB, $rsB);
        $http = new HttpChunkStore('http://fake', 'u', 'p', $xport->asCallable());

        // Push.
        $push = new PushCommand(new SyncEngine(), $csA, $rsA, $http);
        $r = $push->run();
        assertEq('pushed', $r['status']);
        assertTrue($r['transferred'] > 0);

        // Idempotent re-push: zero chunks transferred, ref unchanged.
        $putBefore = $xport->putCount;
        $r2 = $push->run();
        assertEq('up-to-date', $r2['status']);
        assertEq($putBefore, $xport->putCount, 'no further chunks should flow');

        // Now B materializes locally. Use dst WP DB configured for site-b.
        $dstDb = \WpSync\Test\Harness\WpFixture::createDb('https://site-b.test');
        $mat = new Materializer($dstDb, $csB, 'https://site-b.test');
        $mat->materialize($commit, null, $filters);

        // Site B has posts with URL rewritten.
        $res = $dstDb->query("SELECT post_title, post_content FROM wp_posts ORDER BY post_title");
        $rows = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        assertEq(2, count($rows));
        assertEq('<a href="https://site-b.test/x">link</a>', $rows[0]['post_content']);
        // Site-identity options must not have been overwritten.
        $res = $dstDb->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'");
        assertEq('https://site-b.test', $res->fetchArray(SQLITE3_ASSOC)['option_value']);
    });

    TestRun::it('concurrent push: second pusher gets CAS failure', function () {
        $srcA = \WpSync\Test\Harness\WpFixture::createDb('https://site-a.test');
        $srcB = \WpSync\Test\Harness\WpFixture::createDb('https://site-b.test');
        \WpSync\Test\Harness\WpFixture::insertPost($srcA, 'A', 'aa');
        \WpSync\Test\Harness\WpFixture::insertPost($srcB, 'B', 'bb');

        $remoteP = tempnam(sys_get_temp_dir(), 'r_'); unlink($remoteP);
        $csR = new SQLiteChunkStore($remoteP);
        $rsR = SQLiteRefStore::open($remoteP);
        $xport = new \WpSync\Test\Harness\InProcessTransport($csR, $rsR);
        $httpFactory = fn() => new HttpChunkStore('http://x', 'u', 'p', $xport->asCallable());

        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];

        // Pusher A commits & pushes first.
        $pA = tempnam(sys_get_temp_dir(), 'a_'); unlink($pA);
        $csA = new SQLiteChunkStore($pA); $rsA = SQLiteRefStore::open($pA);
        $commitA = (new Snapshotter($srcA, $csA, 'https://site-a.test'))
            ->commit(SchemaInspector::listTables($srcA), 'a', 'm', [], null, $filters);
        $rsA->casRef('refs/heads/main', null, $commitA);
        (new PushCommand(new SyncEngine(), $csA, $rsA, $httpFactory()))->run();

        // Pusher B also thinks remote is null (stale view), commits from own root.
        // Since A already set the ref, B's push must fail non-fast-forward OR
        // CAS — either is acceptable.
        $pB = tempnam(sys_get_temp_dir(), 'b_'); unlink($pB);
        $csB = new SQLiteChunkStore($pB); $rsB = SQLiteRefStore::open($pB);
        $commitB = (new Snapshotter($srcB, $csB, 'https://site-b.test'))
            ->commit(SchemaInspector::listTables($srcB), 'b', 'm', [], null, $filters);
        $rsB->casRef('refs/heads/main', null, $commitB);

        $e = assertThrows(fn() => (new PushCommand(new SyncEngine(), $csB, $rsB, $httpFactory()))->run());
        $msg = $e->getMessage();
        assertTrue(str_contains($msg, 'non-fast-forward') || str_contains($msg, 'concurrently'),
            "expected non-fast-forward or CAS error, got: {$msg}");
    });
});
