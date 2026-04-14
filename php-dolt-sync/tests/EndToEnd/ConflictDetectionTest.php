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

TestRun::group('ConflictDetection', function () {
    TestRun::it('non-fast-forward push is rejected', function () {
        // Setup: A and B share a common ancestor C0. Each commits its own C1.
        // A pushes first; B's push must be rejected non-fast-forward.
        $dbA = \WpSync\Test\Harness\WpFixture::createDb('https://a.test');
        $dbB = \WpSync\Test\Harness\WpFixture::createDb('https://b.test');

        $filters = ['wp_options' => fn($r) => RowNormalizer::isExcludedOption((string)$r['option_name']) ? null : $r];

        // A builds C0, pushes to remote.
        $pA = tempnam(sys_get_temp_dir(), 'a_'); unlink($pA);
        $csA = new SQLiteChunkStore($pA); $rsA = SQLiteRefStore::open($pA);
        $pR = tempnam(sys_get_temp_dir(), 'r_'); unlink($pR);
        $csR = new SQLiteChunkStore($pR); $rsR = SQLiteRefStore::open($pR);
        $xport = new \WpSync\Test\Harness\InProcessTransport($csR, $rsR);
        $http = new HttpChunkStore('http://x','u','p', $xport->asCallable());

        $c0 = (new Snapshotter($dbA, $csA, 'https://a.test'))
            ->commit(SchemaInspector::listTables($dbA), 'a', 'c0', [], null, $filters);
        $rsA->casRef('refs/heads/main', null, $c0);
        (new PushCommand(new SyncEngine(), $csA, $rsA, $http))->run();

        // B fetches C0 (by copying refs + chunks) via a simple copy, not testing fetch here.
        $pB = tempnam(sys_get_temp_dir(), 'b_'); unlink($pB);
        $csB = new SQLiteChunkStore($pB); $rsB = SQLiteRefStore::open($pB);
        // Manually mirror: copy the chunk reachable from c0 into B's store.
        $eng = new SyncEngine();
        $eng->copyReachable($csB, $csR, $c0);
        $rsB->casRef('refs/heads/main', null, $c0);

        // Both make divergent commits from c0.
        \WpSync\Test\Harness\WpFixture::insertPost($dbA, 'A-only', 'aa');
        $cA = (new Snapshotter($dbA, $csA, 'https://a.test'))
            ->commit(SchemaInspector::listTables($dbA), 'a', 'cA', [$c0], null, $filters);
        $rsA->casRef('refs/heads/main', $c0, $cA);

        \WpSync\Test\Harness\WpFixture::insertPost($dbB, 'B-only', 'bb');
        $cB = (new Snapshotter($dbB, $csB, 'https://b.test'))
            ->commit(SchemaInspector::listTables($dbB), 'b', 'cB', [$c0], null, $filters);
        $rsB->casRef('refs/heads/main', $c0, $cB);

        // A pushes cA successfully.
        (new PushCommand(new SyncEngine(), $csA, $rsA, $http))->run();

        // B now tries to push cB which is NOT a descendant of cA -> reject.
        $e = assertThrows(fn() => (new PushCommand(new SyncEngine(), $csB, $rsB, $http))->run());
        assertTrue(str_contains($e->getMessage(), 'non-fast-forward') || str_contains($e->getMessage(), 'concurrently'),
            'expected rejection, got: ' . $e->getMessage());
    });
});
